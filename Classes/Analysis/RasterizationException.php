<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Thrown when a PDF page cannot be rasterized to an image for the OCR-via-vision fallback.
 * Always caught by the caller and treated as "no OCR available" - never a hard error.
 */
class RasterizationException extends \RuntimeException
{
}
