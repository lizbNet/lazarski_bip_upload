<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

/**
 * Thrown for expected, per-item conversion failures (missing binary, timeout, non-zero exit,
 * malformed/missing output). Callers should record the message and continue with the rest
 * of the batch rather than treating it as a system error.
 */
class ConversionException extends \RuntimeException
{
}
