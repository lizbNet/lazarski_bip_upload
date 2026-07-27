<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\DocxMetadataReader;
use PrimeServices\LazarskiBipUpload\Analysis\OpenAiClientInterface;
use PrimeServices\LazarskiBipUpload\Analysis\OpenAiTitleGenerator;
use PrimeServices\LazarskiBipUpload\Analysis\PdfMetadataReader;
use PrimeServices\LazarskiBipUpload\Analysis\PdfPageRasterizerInterface;
use PrimeServices\LazarskiBipUpload\Analysis\RasterizationException;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Service\DocumentAnalysisService;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

$vendorAutoload = null;
for ($dir = __DIR__; $dir !== ($parent = dirname($dir)); $dir = $parent) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $vendorAutoload = $dir . '/vendor/autoload.php';
        break;
    }
}
require_once $vendorAutoload;

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

/**
 * @see Tests/Integration/Analysis/PdfMetadataReaderTest.php for why a hand-built PDF (correct
 * xref table) is used instead of a committed binary fixture.
 *
 * @param array<int, string> $objectBodies
 */
function buildMinimalPdf(array $objectBodies, int $rootObjectNumber, int $infoObjectNumber): string
{
    $output = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objectBodies as $number => $body) {
        $offsets[$number] = strlen($output);
        $output .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
    }

    $xrefOffset = strlen($output);
    $count = count($objectBodies) + 1;
    $output .= sprintf("xref\n0 %d\n", $count);
    $output .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $output .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $output .= "trailer\n";
    $output .= sprintf("<< /Size %d /Root %d 0 R /Info %d 0 R >>\n", $count, $rootObjectNumber, $infoObjectNumber);
    $output .= "startxref\n{$xrefOffset}\n%%EOF";

    return $output;
}

function buildPdfWithText(string $text): string
{
    $streamContent = sprintf('BT /F1 12 Tf 72 720 Td (%s) Tj ET', $text);

    return buildMinimalPdf(
        [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($streamContent), $streamContent),
        ],
        1,
        1
    );
}

function buildEmptyScanPdf(): string
{
    // No /Contents at all - exactly what an unOCR'd scanner PDF looks like to a text parser:
    // just a page (backed by an image XObject, irrelevant here since we only test that no text
    // is extracted), no text stream, no Title.
    return buildMinimalPdf(
        [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
        ],
        1,
        1
    );
}

final class FakeOpenAiClient implements OpenAiClientInterface
{
    /** @var array[] */
    public array $calls = [];

    public function __construct(private \Closure $behavior)
    {
    }

    public function chatCompletionJson(string $apiKey, string $model, array $messages, float $temperature): ?array
    {
        $this->calls[] = ['messages' => $messages];

        return ($this->behavior)($messages);
    }
}

final class FakePdfPageRasterizer implements PdfPageRasterizerInterface
{
    public int $callCount = 0;

    public function __construct(private \Closure $behavior)
    {
    }

    public function rasterizeFirstPage(string $pdfAbsolutePath): string
    {
        $this->callCount++;

        return ($this->behavior)($pdfAbsolutePath);
    }
}

function makeStagingDirWithItem(string $pdfContent, string $filename): array
{
    $stagingRoot = sys_get_temp_dir() . '/lbu_ocr_fallback_test_' . bin2hex(random_bytes(6));
    $setToken = str_repeat('a', 32);
    mkdir($stagingRoot . '/' . $setToken, 0775, true);
    $storedPath = $setToken . '/doc.pdf';
    file_put_contents($stagingRoot . '/' . $storedPath, $pdfContent);

    $item = new DocumentItem();
    $item->setOriginalFilename($filename);
    $item->setFileExtension('pdf');
    $item->setStoredPath($storedPath);

    return [$stagingRoot, $item];
}

function makeService(string $stagingRoot, OpenAiClientInterface $client, PdfPageRasterizerInterface $rasterizer): DocumentAnalysisService
{
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['lazarski_bip_upload'] = [
        'openAiApiKey' => 'sk-test-key',
        'openAiModel' => 'gpt-4o-mini',
    ];

    return new DocumentAnalysisService(
        new TemporaryUploadService($stagingRoot),
        new DocxMetadataReader(),
        new PdfMetadataReader(),
        new OpenAiTitleGenerator($client, new ExtensionConfiguration()),
        $rasterizer
    );
}

function removeDir(string $dir): void
{
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

// --- a PDF with a real, long-enough text layer must NOT trigger the OCR/vision fallback ---
[$stagingRoot, $item] = makeStagingDirWithItem(
    buildPdfWithText('Uchwala Senatu w sprawie ustalenia programu studiow dla kierunku Finanse.'),
    'uchwala-31-2026.pdf'
);
$client = new FakeOpenAiClient(fn () => ['title' => 'Uchwala w sprawie programu studiow', 'description' => 'Opis.']);
$rasterizer = new FakePdfPageRasterizer(fn () => throw new RuntimeException('Must not be called when real text is available.'));
$service = makeService($stagingRoot, $client, $rasterizer);

$result = $service->generateAiSuggestionForItem($item);
assertTrue($rasterizer->callCount === 0, 'The rasterizer must not be invoked when the PDF already has enough real text');
assertTrue($result !== null && $result['title'] === 'Uchwala w sprawie programu studiow', 'The ordinary text-based path must still work for a PDF with a real text layer');
removeDir($stagingRoot);

// --- a PDF with no text layer at all (unOCR'd scan) must trigger the rasterize+vision fallback ---
[$stagingRoot, $item] = makeStagingDirWithItem(buildEmptyScanPdf(), 'uchwala-samorzadu-studentow-27.pdf');
$client = new FakeOpenAiClient(fn (array $messages) => is_array($messages[1]['content'])
    ? ['title' => 'Uchwala Samorzadu Studentow', 'description' => 'Skan uchwaly.']
    : throw new RuntimeException('Expected an image-based (multi-part content) call for a textless scan.'));
$rasterizer = new FakePdfPageRasterizer(fn () => "\x89PNG\r\n\x1a\nfake page image");
$service = makeService($stagingRoot, $client, $rasterizer);

$result = $service->generateAiSuggestionForItem($item);
assertTrue($rasterizer->callCount === 1, 'The rasterizer must be invoked exactly once for a PDF with no text layer');
assertTrue($result !== null && $result['title'] === 'Uchwala Samorzadu Studentow', 'The vision-based result must be returned for a textless scan');
removeDir($stagingRoot);

// --- rasterization failure must fall through to the (weak) text-based attempt, never a hard error ---
[$stagingRoot, $item] = makeStagingDirWithItem(buildEmptyScanPdf(), 'uchwala-samorzadu-studentow-27.pdf');
$client = new FakeOpenAiClient(fn () => ['title' => 'Uchwala Samorzadu Studentow w sprawie ...', 'description' => '']);
$rasterizer = new FakePdfPageRasterizer(fn () => throw new RasterizationException('convert binary not found'));
$service = makeService($stagingRoot, $client, $rasterizer);

$result = $service->generateAiSuggestionForItem($item);
assertTrue($rasterizer->callCount === 1, 'The rasterizer must still be attempted once');
assertTrue($result !== null, 'A rasterization failure must fall through to the text-based attempt rather than returning a hard error');
assertTrue(count($client->calls) === 1, 'Exactly one (text-based, fallen-through) client call must have been made');
assertTrue(!is_array($client->calls[0]['messages'][1]['content']), 'The fallen-through call must be the plain-text path, not an image call');
removeDir($stagingRoot);

echo sprintf("%d DocumentAnalysisService OCR-fallback assertions passed.\n", $assertions);
