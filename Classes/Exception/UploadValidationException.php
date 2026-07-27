<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Exception;

/**
 * Thrown for expected, per-file validation failures (wrong type, too large, too many files, ...).
 * Callers should present the message to the editor rather than treating it as a system error.
 */
class UploadValidationException extends \RuntimeException
{
}
