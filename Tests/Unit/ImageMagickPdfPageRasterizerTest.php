<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\ImageMagickPdfPageRasterizer;
use PrimeServices\LazarskiBipUpload\Analysis\RasterizationException;
use PrimeServices\LazarskiBipUpload\Conversion\ProcessResult;
use PrimeServices\LazarskiBipUpload\Conversion\ProcessRunnerInterface;

require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessResult.php';
require_once dirname(__DIR__, 2) . '/Classes/Conversion/ProcessRunnerInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Analysis/RasterizationException.php';
require_once dirname(__DIR__, 2) . '/Classes/Analysis/PdfPageRasterizerInterface.php';
require_once dirname(__DIR__, 2) . '/Classes/Analysis/ImageMagickPdfPageRasterizer.php';

/**
 * Test double standing in for the real `convert` process: lets each scenario control the
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

function fakePngBytes(): string
{
    return "\x89PNG\r\n\x1a\nfake png payload";
}

// --- success: output file is written and passes PNG verification ---
$runner = new FakeProcessRunner(function (array $command): ProcessResult {
    // The output path is always the last argument.
    file_put_contents($command[count($command) - 1], fakePngBytes());
    return new ProcessResult(0, '', '', false);
});
$rasterizer = new ImageMagickPdfPageRasterizer($runner, 'convert', 200, 30);
$bytes = $rasterizer->rasterizeFirstPage('/some/where/document.pdf');
assertTrue($bytes === fakePngBytes(), 'Must return the raw PNG bytes produced by the process');

// --- command array proves no shell interpolation is possible, and only page 1 is requested ---
$command = $runner->calls[0]['command'];
assertTrue(is_array($command), 'The runner must receive an argument array, not a shell string');
assertTrue($command[0] === 'convert', 'First argument must be the configured binary path');
assertTrue(in_array('-density', $command, true), 'Must pass -density explicitly');
assertTrue($command[3] === '/some/where/document.pdf[0]', 'Must request only the first page via the [0] page selector');
assertTrue(!str_contains(implode(' ', $command), ';'), 'Sanity: no shell metacharacter smuggling in the built command');

// --- the temporary output file/directory must be cleaned up afterward ---
$outputPath = $command[count($command) - 1];
assertTrue(!is_file($outputPath), 'The temporary output file must be removed after a successful call');
assertTrue(!is_dir(dirname($outputPath)), 'The temporary directory must be removed after a successful call');

// --- timeout ---
$runner = new FakeProcessRunner(fn () => new ProcessResult(-1, '', '', true));
$rasterizer = new ImageMagickPdfPageRasterizer($runner);
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException for a timed-out process.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'timed out'), 'Timeout message must mention the timeout');
}

// --- missing binary (runner reports a non-zero/-1 exit, no timeout) ---
$runner = new FakeProcessRunner(fn () => new ProcessResult(-1, '', 'command not found', false));
$rasterizer = new ImageMagickPdfPageRasterizer($runner, '/nonexistent/convert');
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException for a missing binary.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'status -1'), 'Missing-binary failure must surface as a non-zero/-1 exit status');
}

// --- non-zero exit ---
$runner = new FakeProcessRunner(fn () => new ProcessResult(1, '', 'delegate failed', false));
$rasterizer = new ImageMagickPdfPageRasterizer($runner);
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException for a non-zero exit.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'status 1'), 'Non-zero exit message must mention the exit status');
}

// --- malformed output: exit 0 but not actually a PNG (untrustworthy-exit-code case) ---
$runner = new FakeProcessRunner(function (array $command): ProcessResult {
    file_put_contents($command[count($command) - 1], 'not a png at all');
    return new ProcessResult(0, '', '', false);
});
$rasterizer = new ImageMagickPdfPageRasterizer($runner);
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException for malformed (non-PNG) output despite exit code 0.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'not a valid PNG'), 'Malformed-output message must say the output is not a valid PNG');
}

// --- missing output entirely: exit 0 but convert wrote nothing ---
$runner = new FakeProcessRunner(fn () => new ProcessResult(0, '', '', false));
$rasterizer = new ImageMagickPdfPageRasterizer($runner);
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException when no output file is produced.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'did not produce'), 'Missing-output message must say no output file was produced');
}

// --- empty output file: exit 0, file exists but is 0 bytes ---
$runner = new FakeProcessRunner(function (array $command): ProcessResult {
    file_put_contents($command[count($command) - 1], '');
    return new ProcessResult(0, '', '', false);
});
$rasterizer = new ImageMagickPdfPageRasterizer($runner);
try {
    $rasterizer->rasterizeFirstPage('/x.pdf');
    throw new RuntimeException('Expected RasterizationException for an empty output file.');
} catch (RasterizationException $e) {
    assertTrue(str_contains($e->getMessage(), 'empty output'), 'Empty-output message must say the output file is empty');
}

echo sprintf("%d ImageMagickPdfPageRasterizer assertions passed.\n", $assertions);
