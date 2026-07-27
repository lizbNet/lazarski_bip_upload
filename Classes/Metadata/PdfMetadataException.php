<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Metadata;

/**
 * Thrown when writing or verifying a PDF's title metadata fails. Confirmation must not
 * proceed if this is thrown - a set with unverifiable file metadata is not safely confirmable.
 */
class PdfMetadataException extends \RuntimeException
{
}
