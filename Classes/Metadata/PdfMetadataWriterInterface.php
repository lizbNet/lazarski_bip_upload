<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Metadata;

/**
 * Writes the editor-approved title into a final PDF's metadata. Implementations must verify
 * the write actually took effect by reading the value back - a non-error response alone is not
 * proof of success.
 */
interface PdfMetadataWriterInterface
{
    /**
     * @throws PdfMetadataException if the write or the read-back verification fails
     */
    public function writeApprovedTitle(string $absolutePdfPath, string $title): void;
}
