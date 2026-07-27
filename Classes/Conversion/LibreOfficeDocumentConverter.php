<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

/**
 * Converts DOCX to PDF via headless LibreOffice, requesting tagged-PDF export as a baseline
 * accessibility improvement - this is not a PDF/UA conformance guarantee, only as good as the
 * source document's own heading/structure semantics.
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

            $command = [
                $this->binaryPath,
                '-env:UserInstallation=file://' . $profileDirectory,
                '--headless',
                '--nologo',
                '--nofirststartwizard',
                '--norestore',
                '--convert-to',
                'pdf:writer_pdf_Export:{"UseTaggedPDF":{"type":"boolean","value":"true"}}',
                '--outdir',
                $outputDirectoryAbsolutePath,
                $sourceAbsolutePath,
            ];

            $result = $this->processRunner->run($command, $this->timeoutSeconds);

            if ($result->timedOut) {
                throw new ConversionException(sprintf('Conversion timed out after %d seconds.', $this->timeoutSeconds));
            }
            if ($result->exitCode !== 0) {
                throw new ConversionException(sprintf('Conversion process exited with status %d.', $result->exitCode));
            }

            $expectedOutputPath = rtrim($outputDirectoryAbsolutePath, '/') . '/'
                . pathinfo($sourceAbsolutePath, PATHINFO_FILENAME) . '.pdf';

            $this->assertValidPdf($expectedOutputPath);

            return $expectedOutputPath;
        } finally {
            if (is_dir($profileDirectory)) {
                $this->removeDirectoryRecursively($profileDirectory);
            }
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
