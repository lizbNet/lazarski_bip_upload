<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Metadata;

use PrimeServices\LazarskiBipUpload\Conversion\ProcessRunnerInterface;

/**
 * Writes the editor-approved title into a final PDF's metadata (both the classic /Info
 * dictionary and the XMP dc:title, since readers vary in which one they trust) and verifies
 * the write by reading the value back - a non-zero exit code alone is not proof the write
 * actually took effect, mirroring the same lesson learned from LibreOffice conversion in
 * Step 2.
 *
 * Uses exiftool via ProcessRunnerInterface (the same seam Conversion\LibreOfficeDocumentConverter
 * uses), reusing its test-doubling story: tests substitute a fake runner rather than invoking
 * a real binary.
 *
 * Stays as the DDEV reference implementation (exiftool is installed there via
 * .ddev/web-build/Dockerfile) - production is wired to GotenbergPdfMetadataService instead,
 * since exiftool doesn't exist on the home.pl host. See DocumentConverterInterface/
 * GotenbergDocumentConverter for the identical pattern this mirrors.
 */
class PdfMetadataService implements PdfMetadataWriterInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $binaryPath = 'exiftool',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @throws PdfMetadataException if the write or the read-back verification fails
     */
    public function writeApprovedTitle(string $absolutePdfPath, string $title): void
    {
        $writeResult = $this->processRunner->run(
            [
                $this->binaryPath,
                '-overwrite_original',
                '-Title=' . $title,
                '-XMP-dc:Title=' . $title,
                $absolutePdfPath,
            ],
            $this->timeoutSeconds
        );

        if ($writeResult->timedOut) {
            throw new PdfMetadataException('Writing PDF title metadata timed out.');
        }
        if ($writeResult->exitCode !== 0) {
            throw new PdfMetadataException(sprintf('Writing PDF title metadata exited with status %d.', $writeResult->exitCode));
        }

        $actualTitle = $this->readTitle($absolutePdfPath);
        if ($actualTitle !== $title) {
            throw new PdfMetadataException('Could not verify the PDF title metadata was written correctly.');
        }
    }

    private function readTitle(string $absolutePdfPath): string
    {
        $readResult = $this->processRunner->run(
            [$this->binaryPath, '-s', '-s', '-s', '-Title', $absolutePdfPath],
            $this->timeoutSeconds
        );

        if ($readResult->timedOut || $readResult->exitCode !== 0) {
            throw new PdfMetadataException('Could not read back the PDF title metadata for verification.');
        }

        return trim($readResult->stdout);
    }
}
