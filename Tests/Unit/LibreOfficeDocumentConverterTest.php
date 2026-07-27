<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Conversion\ConversionException;
use PrimeServices\LazarskiBipUpload\Conversion\LibreOfficeDocumentConverter;
use PrimeServices\LazarskiBipUpload\Conversion\ProcessResult;
use PrimeServices\LazarskiBipUpload\Conversion\ProcessRunnerInterface;

require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessResult.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessRunnerInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/ConversionException.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/DocumentConverterInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/LibreOfficeDocumentConverter.php';

/**
 * Test double standing in for the real soffice process: lets each scenario control the
 * exit code/timeout and optionally write a fake output file, without invoking a real binary.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var array[] */
    public array $calls = [];

    public function __construct(private \Closure $behavior)
    {
    }

    public function run(array $command, ?int $timeoutSeconds): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'timeout' => $timeoutSeconds];

        return ($this->behavior)($command, $timeoutSeconds);
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

function makeScratchDir(): string
{
    $dir = sys_get_temp_dir() . '/lbu_conv_test_' . bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    return $dir;
}

function removeDir(string $dir): void
{
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

// --- success: output file is written and passes verification ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/abc123.docx';
file_put_contents($sourcePath, 'fake docx content');

$runner = new FakeProcessRunner(function (array $command) use ($outputDir): ProcessResult {
    file_put_contents($outputDir . '/abc123.pdf', '%PDF-1.4 minimal valid pdf');
    return new ProcessResult(0, '', '', false);
});
$converter = new LibreOfficeDocumentConverter($runner, 'soffice', 60);
$result = $converter->convertToPdf($sourcePath, $outputDir);
assertTrue($result === $outputDir . '/abc123.pdf', 'Must return the absolute path of the produced PDF');
assertTrue(is_file($result), 'The output file must actually exist on disk');

// --- command array proves no shell interpolation is possible ---
$command = $runner->calls[0]['command'];
assertTrue(is_array($command), 'The runner must receive an argument array, not a shell string');
assertTrue($command[0] === 'soffice', 'First argument must be the configured binary path');
assertTrue(str_starts_with($command[1], '-env:UserInstallation=file://'), 'A fresh UserInstallation profile must be passed');
assertTrue(in_array('--headless', $command, true), 'Must invoke headless mode');
assertTrue(
    in_array('pdf:writer_pdf_Export:{"UseTaggedPDF":{"type":"boolean","value":"true"}}', $command, true),
    'Must request tagged-PDF export via the documented LibreOffice filter-options syntax'
);
assertTrue($command[count($command) - 1] === $sourcePath, 'The source path must be the final argument, passed verbatim (never interpolated)');
assertTrue(!str_contains(implode(' ', $command), ';') , 'Sanity: no shell metacharacter smuggling in the built command');

removeDir($sourceDir);
removeDir($outputDir);

// --- timeout ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/timeout.docx';
file_put_contents($sourcePath, 'x');
$runner = new FakeProcessRunner(fn () => new ProcessResult(-1, '', '', true));
$converter = new LibreOfficeDocumentConverter($runner);
try {
    $converter->convertToPdf($sourcePath, $outputDir);
    throw new RuntimeException('Expected ConversionException for a timed-out process.');
} catch (ConversionException $e) {
    assertTrue(str_contains($e->getMessage(), 'timed out'), 'Timeout message must mention the timeout');
}
removeDir($sourceDir);
removeDir($outputDir);

// --- missing binary (runner reports a non-zero/-1 exit, no timeout) ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/missing.docx';
file_put_contents($sourcePath, 'x');
$runner = new FakeProcessRunner(fn () => new ProcessResult(-1, '', 'command not found', false));
$converter = new LibreOfficeDocumentConverter($runner, '/nonexistent/soffice');
try {
    $converter->convertToPdf($sourcePath, $outputDir);
    throw new RuntimeException('Expected ConversionException for a missing binary.');
} catch (ConversionException $e) {
    assertTrue(str_contains($e->getMessage(), 'status -1'), 'Missing-binary failure must surface as a non-zero/-1 exit status');
}
removeDir($sourceDir);
removeDir($outputDir);

// --- non-zero exit ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/broken.docx';
file_put_contents($sourcePath, 'x');
$runner = new FakeProcessRunner(fn () => new ProcessResult(1, '', 'filter crashed', false));
$converter = new LibreOfficeDocumentConverter($runner);
try {
    $converter->convertToPdf($sourcePath, $outputDir);
    throw new RuntimeException('Expected ConversionException for a non-zero exit.');
} catch (ConversionException $e) {
    assertTrue(str_contains($e->getMessage(), 'status 1'), 'Non-zero exit message must mention the exit status');
}
removeDir($sourceDir);
removeDir($outputDir);

// --- malformed output: exit 0 but no real PDF produced (soffice's untrustworthy-exit-code case) ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/malformed.docx';
file_put_contents($sourcePath, 'x');
$runner = new FakeProcessRunner(function () use ($outputDir): ProcessResult {
    file_put_contents($outputDir . '/malformed.pdf', 'not a pdf at all');
    return new ProcessResult(0, '', '', false);
});
$converter = new LibreOfficeDocumentConverter($runner);
try {
    $converter->convertToPdf($sourcePath, $outputDir);
    throw new RuntimeException('Expected ConversionException for malformed (non-PDF) output despite exit code 0.');
} catch (ConversionException $e) {
    assertTrue(str_contains($e->getMessage(), 'not a valid PDF'), 'Malformed-output message must say the output is not a valid PDF');
    assertTrue(!is_file($outputDir . '/malformed.pdf'), 'The malformed output file must be removed');
}
removeDir($sourceDir);
removeDir($outputDir);

// --- missing output entirely: exit 0 but soffice wrote nothing ---
$sourceDir = makeScratchDir();
$outputDir = makeScratchDir();
$sourcePath = $sourceDir . '/nooutput.docx';
file_put_contents($sourcePath, 'x');
$runner = new FakeProcessRunner(fn () => new ProcessResult(0, '', '', false));
$converter = new LibreOfficeDocumentConverter($runner);
try {
    $converter->convertToPdf($sourcePath, $outputDir);
    throw new RuntimeException('Expected ConversionException when no output file is produced.');
} catch (ConversionException $e) {
    assertTrue(str_contains($e->getMessage(), 'did not produce'), 'Missing-output message must say no output file was produced');
}
removeDir($sourceDir);
removeDir($outputDir);

echo sprintf("%d LibreOfficeDocumentConverter assertions passed.\n", $assertions);
