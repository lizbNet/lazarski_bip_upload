<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

use PrimeServices\LazarskiBipUpload\Conversion\ProcessRunnerInterface;

/**
 * Rasterizes a PDF's first page to PNG via ImageMagick's `convert` (using its Ghostscript
 * delegate for the PDF->raster step - both already present in the webimage for GFX processing,
 * no new system packages needed for this half of the OCR fallback).
 *
 * As with LibreOfficeDocumentConverter, the exit code alone is not trustworthy: verify the
 * output file actually exists, is non-empty, and starts with the PNG magic bytes.
 */
class ImageMagickPdfPageRasterizer implements PdfPageRasterizerInterface
{
    private const PNG_MAGIC_BYTES = "\x89PNG";

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $binaryPath = 'convert',
        private readonly int $density = 200,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function rasterizeFirstPage(string $pdfAbsolutePath): string
    {
        $tempDirectory = rtrim(sys_get_temp_dir(), '/') . '/lbu_rasterize_' . bin2hex(random_bytes(8));
        $outputPath = $tempDirectory . '/page.png';

        try {
            if (!@mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
                throw new RasterizationException('Could not create a temporary directory for rasterization.');
            }

            $command = [
                $this->binaryPath,
                '-density',
                (string)$this->density,
                $pdfAbsolutePath . '[0]',
                $outputPath,
            ];

            $result = $this->processRunner->run($command, $this->timeoutSeconds);

            if ($result->timedOut) {
                throw new RasterizationException(sprintf('Rasterization timed out after %d seconds.', $this->timeoutSeconds));
            }
            if ($result->exitCode !== 0) {
                throw new RasterizationException(sprintf('Rasterization process exited with status %d.', $result->exitCode));
            }

            return $this->readAndVerifyPng($outputPath);
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            if (is_dir($tempDirectory)) {
                @rmdir($tempDirectory);
            }
        }
    }

    private function readAndVerifyPng(string $path): string
    {
        if (!is_file($path)) {
            throw new RasterizationException('Rasterization did not produce an output file.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new RasterizationException('Rasterization produced an empty output file.');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || !str_starts_with($bytes, self::PNG_MAGIC_BYTES)) {
            throw new RasterizationException('Rasterization output is not a valid PNG.');
        }

        return $bytes;
    }
}
