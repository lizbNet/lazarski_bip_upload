<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Conversion\ProcessResult;
use PrimeServices\LazarskiBipUpload\Conversion\ProcessRunnerInterface;
use PrimeServices\LazarskiBipUpload\Metadata\PdfMetadataException;
use PrimeServices\LazarskiBipUpload\Metadata\PdfMetadataService;

require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessResult.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessRunnerInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Metadata/PdfMetadataException.php';
require_once dirname(__DIR__, 2) . '/Classes/Metadata/PdfMetadataWriterInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Metadata/PdfMetadataService.php';

/**
 * Test double standing in for exiftool: each scenario controls what the "write" call and
 * the subsequent "read back" call return, without invoking a real binary.
 */
final class FakeMetadataProcessRunner implements ProcessRunnerInterface
{
    /** @var array[] */
    public array $calls = [];

    public function __construct(private \Closure $behavior)
    {
    }

    public function run(array $command, ?int $timeoutSeconds): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'timeout' => $timeoutSeconds];

        return ($this->behavior)($command, $timeoutSeconds, count($this->calls));
    }
}

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- success: write exits 0, read-back returns the exact title ---
$runner = new FakeMetadataProcessRunner(function (array $command, ?int $timeout, int $callNumber) {
    if ($callNumber === 1) {
        return new ProcessResult(0, '', '', false);
    }
    return new ProcessResult(0, 'Uchwała nr 1', '', false);
});
$service = new PdfMetadataService($runner, 'exiftool', 30);
$service->writeApprovedTitle('/tmp/example.pdf', 'Uchwała nr 1');
assertTrue(count($runner->calls) === 2, 'Success must invoke exactly two process calls: write, then read-back');

$writeCommand = $runner->calls[0]['command'];
assertTrue($writeCommand[0] === 'exiftool', 'First argument must be the configured binary path');
assertTrue(in_array('-overwrite_original', $writeCommand, true), 'Write must pass -overwrite_original to avoid a stray backup file');
assertTrue(in_array('-Title=Uchwała nr 1', $writeCommand, true), 'Write must set the Info dictionary Title');
assertTrue(in_array('-XMP-dc:Title=Uchwała nr 1', $writeCommand, true), 'Write must also set the XMP dc:Title');
assertTrue($writeCommand[count($writeCommand) - 1] === '/tmp/example.pdf', 'The PDF path must be the final argument, passed verbatim');

$readCommand = $runner->calls[1]['command'];
assertTrue(in_array('-Title', $readCommand, true), 'Read-back must query the Title tag');

// --- write itself fails (non-zero exit) ---
$runner = new FakeMetadataProcessRunner(fn () => new ProcessResult(1, '', 'exiftool error', false));
$service = new PdfMetadataService($runner);
try {
    $service->writeApprovedTitle('/tmp/example.pdf', 'Some Title');
    throw new RuntimeException('Expected PdfMetadataException for a non-zero write exit code.');
} catch (PdfMetadataException $e) {
    assertTrue(str_contains($e->getMessage(), 'status 1'), 'Write failure message must mention the exit status');
}

// --- write times out ---
$runner = new FakeMetadataProcessRunner(fn () => new ProcessResult(-1, '', '', true));
$service = new PdfMetadataService($runner);
try {
    $service->writeApprovedTitle('/tmp/example.pdf', 'Some Title');
    throw new RuntimeException('Expected PdfMetadataException for a timed-out write.');
} catch (PdfMetadataException $e) {
    assertTrue(str_contains($e->getMessage(), 'timed out'), 'Timeout message must mention the timeout');
}

// --- write exits 0 but the read-back does not match: verification must catch this ---
$runner = new FakeMetadataProcessRunner(function (array $command, ?int $timeout, int $callNumber) {
    if ($callNumber === 1) {
        return new ProcessResult(0, '', '', false);
    }
    return new ProcessResult(0, 'A Completely Different Title', '', false);
});
$service = new PdfMetadataService($runner);
try {
    $service->writeApprovedTitle('/tmp/example.pdf', 'Intended Title');
    throw new RuntimeException('Expected PdfMetadataException when the read-back value does not match what was written.');
} catch (PdfMetadataException $e) {
    assertTrue(str_contains($e->getMessage(), 'verify'), 'Mismatch message must mention verification failure');
}

// --- read-back itself fails after a successful-looking write ---
$runner = new FakeMetadataProcessRunner(function (array $command, ?int $timeout, int $callNumber) {
    if ($callNumber === 1) {
        return new ProcessResult(0, '', '', false);
    }
    return new ProcessResult(1, '', 'read error', false);
});
$service = new PdfMetadataService($runner);
try {
    $service->writeApprovedTitle('/tmp/example.pdf', 'Some Title');
    throw new RuntimeException('Expected PdfMetadataException when the read-back call itself fails.');
} catch (PdfMetadataException $e) {
    assertTrue(str_contains($e->getMessage(), 'read back'), 'Read-back failure message must say verification could not be read');
}

echo sprintf("%d PdfMetadataService assertions passed.\n", $assertions);
