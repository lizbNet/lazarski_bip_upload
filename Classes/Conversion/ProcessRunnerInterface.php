<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

/**
 * Seam between LibreOfficeDocumentConverter and the actual process execution, so tests can
 * exercise success/timeout/missing-binary/malformed-output/non-zero-exit without invoking
 * a real soffice binary.
 */
interface ProcessRunnerInterface
{
    /**
     * @param string[] $command argument-array form (never a shell string)
     */
    public function run(array $command, ?int $timeoutSeconds): ProcessResult;
}
