<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Converts DOCX/XLSX to PDF via headless LibreOffice, requesting tagged-PDF export as a baseline
 * accessibility improvement - this is not a PDF/UA conformance guarantee, only as good as the
 * source document's own heading/structure semantics.
 *
 * XLSX gets two extra treatments so a wide sheet doesn't get sliced across a grid of pages:
 * landscape orientation (forced on the source file itself via PhpSpreadsheet - LibreOffice has
 * no command-line/filter-data option for this, it only honours whatever the document's own page
 * style says) and the SinglePageSheets export filter option (scales each sheet to fit one page).
 *
 * The process exit code alone is not trustworthy: soffice can exit 0 having produced nothing
 * (e.g. a filter crash swallowed internally), so the output file is independently verified.
 */
class LibreOfficeDocumentConverter implements DocumentConverterInterface
{
    private const OUTPUT_VERIFICATION_MAGIC_BYTES = '%PDF-';

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $binaryPath = 'soffice',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function convertToPdf(string $sourceAbsolutePath, string $outputDirectoryAbsolutePath): string
    {
        $profileDirectory = rtrim(sys_get_temp_dir(), '/') . '/lbu_soffice_profile_' . bin2hex(random_bytes(8));

        try {
            if (!@mkdir($profileDirectory, 0775, true) && !is_dir($profileDirectory)) {
                throw new ConversionException('Could not create a LibreOffice user profile directory.');
            }

            $conversionSourcePath = $sourceAbsolutePath;
            $exportFilter = 'writer_pdf_Export:{"UseTaggedPDF":{"type":"boolean","value":"true"}}';

            if (strtolower(pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION)) === 'xlsx') {
                $exportFilter = 'calc_pdf_Export:{"UseTaggedPDF":{"type":"boolean","value":"true"},'
                    . '"SinglePageSheets":{"type":"boolean","value":"true"}}';
                $landscapeCopyPath = $this->createLandscapeCopy($sourceAbsolutePath, $profileDirectory);
                if ($landscapeCopyPath !== null) {
                    $conversionSourcePath = $landscapeCopyPath;
                }
            }

            $command = [
                $this->binaryPath,
                '-env:UserInstallation=file://' . $profileDirectory,
                '--headless',
                '--nologo',
                '--nofirststartwizard',
                '--norestore',
                '--convert-to',
                'pdf:' . $exportFilter,
                '--outdir',
                $outputDirectoryAbsolutePath,
                $conversionSourcePath,
            ];

            $result = $this->processRunner->run($command, $this->timeoutSeconds);

            if ($result->timedOut) {
                throw new ConversionException(sprintf('Conversion timed out after %d seconds.', $this->timeoutSeconds));
            }
            if ($result->exitCode !== 0) {
                throw new ConversionException(sprintf('Conversion process exited with status %d.', $result->exitCode));
            }

            $expectedOutputPath = rtrim($outputDirectoryAbsolutePath, '/') . '/'
                . pathinfo($conversionSourcePath, PATHINFO_FILENAME) . '.pdf';

            $this->assertValidPdf($expectedOutputPath);

            return $expectedOutputPath;
        } finally {
            if (is_dir($profileDirectory)) {
                $this->removeDirectoryRecursively($profileDirectory);
            }
        }
    }

    /**
     * Forces landscape orientation on every sheet of an XLSX source, saving the result under its
     * original basename inside $temporaryDirectory (kept alongside the LibreOffice profile, so it
     * is cleaned up the same way). Best-effort: returns null on any failure, so the caller falls
     * back to converting the untouched (portrait) original - still benefiting from SinglePageSheets.
     */
    private function createLandscapeCopy(string $sourceAbsolutePath, string $temporaryDirectory): ?string
    {
        try {
            $spreadsheet = IOFactory::load($sourceAbsolutePath);
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            }
            $copyPath = rtrim($temporaryDirectory, '/') . '/' . basename($sourceAbsolutePath);
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($copyPath);
            return $copyPath;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertValidPdf(string $path): void
    {
        if (!is_file($path)) {
            throw new ConversionException('Conversion did not produce an output file.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ConversionException('Conversion produced an empty output file.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ConversionException('Conversion output file could not be read for verification.');
        }
        $header = fread($handle, strlen(self::OUTPUT_VERIFICATION_MAGIC_BYTES));
        fclose($handle);

        if ($header !== self::OUTPUT_VERIFICATION_MAGIC_BYTES) {
            @unlink($path);
            throw new ConversionException('Conversion output is not a valid PDF.');
        }
    }

    private function removeDirectoryRecursively(string $directory): void
    {
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
