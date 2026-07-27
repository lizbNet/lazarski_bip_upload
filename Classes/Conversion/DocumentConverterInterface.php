<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

/**
 * Converts a source document into a PDF. Implementations must never interpolate
 * paths into a shell string and must verify the produced output is a real, non-empty PDF -
 * a process exit code alone is not sufficient proof of success.
 */
interface DocumentConverterInterface
{
    /**
     * @param string $sourceAbsolutePath absolute path to the source document (e.g. a .docx)
     * @param string $outputDirectoryAbsolutePath absolute path of an existing, writable directory
     * @return string absolute path of the verified, non-empty PDF that was produced
     * @throws ConversionException for any expected, editor-facing failure (missing binary,
     *         timeout, non-zero exit, malformed/missing output)
     */
    public function convertToPdf(string $sourceAbsolutePath, string $outputDirectoryAbsolutePath): string;
}
