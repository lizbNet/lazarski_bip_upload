<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\OpenAiClientInterface;
use PrimeServices\LazarskiBipUpload\Analysis\OpenAiTitleGenerator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

$vendorAutoload = null;
for ($dir = __DIR__; $dir !== ($parent = dirname($dir)); $dir = $parent) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $vendorAutoload = $dir . '/vendor/autoload.php';
        break;
    }
}
require_once $vendorAutoload;

/**
 * Test double for the real HTTP call: each scenario controls what the "model" replies with,
 * without a real network call or API key. Also records the exact payload passed in, so tests
 * can assert the prompt/messages structure without depending on their literal wording.
 */
final class FakeOpenAiClient implements OpenAiClientInterface
{
    /** @var array[] */
    public array $calls = [];

    public function __construct(private \Closure $behavior)
    {
    }

    public function chatCompletionJson(string $apiKey, string $model, array $messages, float $temperature): ?array
    {
        $this->calls[] = ['apiKey' => $apiKey, 'model' => $model, 'messages' => $messages, 'temperature' => $temperature];

        return ($this->behavior)($apiKey, $model, $messages, $temperature);
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

function makeGenerator(\Closure $behavior, array $extConf = ['openAiApiKey' => 'sk-test-key', 'openAiModel' => 'gpt-4o-mini']): array
{
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['lazarski_bip_upload'] = $extConf;
    $client = new FakeOpenAiClient($behavior);
    $generator = new OpenAiTitleGenerator($client, new ExtensionConfiguration());

    return [$generator, $client];
}

// --- unconfigured (no API key): must return null immediately, without calling the client ---
[$generator, $client] = makeGenerator(fn () => throw new RuntimeException('Must not be called when unconfigured.'), ['openAiApiKey' => '', 'openAiModel' => '']);
assertTrue(!$generator->isConfigured(), 'isConfigured() must be false when the API key setting is empty');
$result = $generator->generate('uchwala', [['filename' => 'a.pdf', 'extractedText' => 'text']]);
assertTrue($result === null, 'generate() must return null immediately when unconfigured');
assertTrue(count($client->calls) === 0, 'The HTTP client must never be invoked when unconfigured');

// --- configured: isConfigured() is true ---
[$generator] = makeGenerator(fn () => null);
assertTrue($generator->isConfigured(), 'isConfigured() must be true when the API key setting is non-empty');

// --- successful generation with page title, subtitle, slug, and per-item titles ---
[$generator, $client] = makeGenerator(fn () => [
    'pageTitle' => 'Uchwała w sprawie zmiany planu studiów',
    'subtitle' => 'Uchwała nr 31/2026 Senatu Uczelni Łazarskiego z dnia 25 czerwca 2026 r.',
    'slug' => 'uchwala-31-2026',
    'itemTitles' => ['Uchwała w sprawie zmiany planu studiów', 'Skan uchwały w sprawie zmiany planu studiów'],
]);
$result = $generator->generate('uchwala', [
    ['filename' => 'a.docx', 'extractedText' => 'tresc a'],
    ['filename' => 'b.pdf', 'extractedText' => 'tresc b'],
]);
assertTrue($result !== null, 'A well-formed response must produce a non-null result');
assertTrue($result['pageTitle'] === 'Uchwała w sprawie zmiany planu studiów', 'pageTitle must be passed through');
assertTrue($result['subtitle'] === 'Uchwała nr 31/2026 Senatu Uczelni Łazarskiego z dnia 25 czerwca 2026 r.', 'subtitle must be passed through');
assertTrue($result['slug'] === 'uchwala-31-2026', 'An already-clean slug must pass through unchanged');
assertTrue($result['itemTitles'] === ['Uchwała w sprawie zmiany planu studiów', 'Skan uchwały w sprawie zmiany planu studiów'], 'itemTitles must be passed through positionally');

// --- a messy/non-conforming slug from the model is still sanitized, never trusted blindly ---
[$generator] = makeGenerator(fn () => ['pageTitle' => 'X', 'subtitle' => '', 'slug' => 'Uchwała Nr 31/2026!', 'itemTitles' => ['']]);
$result = $generator->generate('uchwala', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($result['slug'] === 'uchwala-nr-31-2026', 'A messy model-provided slug must be sanitized (diacritics/case/punctuation), never trusted as-is');

// --- missing slug in the response defaults to an empty string, not an error ---
[$generator] = makeGenerator(fn () => ['pageTitle' => 'X', 'subtitle' => '', 'itemTitles' => ['']]);
$result = $generator->generate('uchwala', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($result !== null, 'A response with no slug field must still be usable');
assertTrue($result['slug'] === '', 'A missing slug field must default to an empty string');

$call = $client->calls[0];
assertTrue($call['apiKey'] === 'sk-test-key', 'The configured API key must be passed to the client');
assertTrue($call['model'] === 'gpt-4o-mini', 'The configured model must be passed to the client');
assertTrue(count($call['messages']) === 2, 'Must send exactly a system and a user message');
assertTrue($call['messages'][0]['role'] === 'system', 'First message must be the system prompt');
assertTrue($call['messages'][1]['role'] === 'user', 'Second message must be the user prompt');
assertTrue(str_contains($call['messages'][1]['content'], 'a.docx'), 'The user prompt must include each filename');
assertTrue(str_contains($call['messages'][1]['content'], 'tresc a'), 'The user prompt must include each item\'s extracted text');

// --- custom model setting is honored ---
[$generator, $client] = makeGenerator(fn () => ['pageTitle' => 'X', 'subtitle' => '', 'itemTitles' => ['']], ['openAiApiKey' => 'sk-test', 'openAiModel' => 'gpt-4o']);
$generator->generate('', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($client->calls[0]['model'] === 'gpt-4o', 'A custom configured model must be used instead of the default');

// --- missing model setting falls back to the documented default ---
[$generator, $client] = makeGenerator(fn () => ['pageTitle' => 'X', 'subtitle' => '', 'itemTitles' => ['']], ['openAiApiKey' => 'sk-test', 'openAiModel' => '']);
$generator->generate('', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($client->calls[0]['model'] === 'gpt-4o-mini', 'An empty model setting must fall back to gpt-4o-mini');

// --- client returns null (network error / timeout / non-200 / malformed JSON): must propagate as null ---
[$generator] = makeGenerator(fn () => null);
$result = $generator->generate('uchwala', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($result === null, 'A null client response must propagate as null (fall back to heuristic)');

// --- response missing pageTitle must be treated as unusable ---
[$generator] = makeGenerator(fn () => ['subtitle' => 'x', 'itemTitles' => ['x']]);
$result = $generator->generate('uchwala', [['filename' => 'a.pdf', 'extractedText' => 't']]);
assertTrue($result === null, 'A response with no pageTitle must be treated as invalid/unusable');

// --- response with fewer itemTitles than items: missing ones default to empty string, not an error ---
[$generator] = makeGenerator(fn () => ['pageTitle' => 'Title', 'subtitle' => '', 'itemTitles' => ['Only one']]);
$result = $generator->generate('uchwala', [
    ['filename' => 'a.pdf', 'extractedText' => 't'],
    ['filename' => 'b.pdf', 'extractedText' => 't'],
]);
assertTrue($result !== null, 'A short itemTitles array must not invalidate the whole response');
assertTrue($result['itemTitles'] === ['Only one', ''], 'Missing item titles must default to an empty string, positionally');

// --- empty items list short-circuits without calling the client ---
[$generator, $client] = makeGenerator(fn () => throw new RuntimeException('Must not be called with zero items.'));
$result = $generator->generate('uchwala', []);
assertTrue($result === null, 'generate() with zero items must return null');
assertTrue(count($client->calls) === 0, 'The client must not be invoked for an empty item list');

// --- generateForItem(): unconfigured must return null immediately, without calling the client ---
[$generator, $client] = makeGenerator(fn () => throw new RuntimeException('Must not be called when unconfigured.'), ['openAiApiKey' => '', 'openAiModel' => '']);
$result = $generator->generateForItem('a.pdf', 'text');
assertTrue($result === null, 'generateForItem() must return null immediately when unconfigured');
assertTrue(count($client->calls) === 0, 'The HTTP client must never be invoked when unconfigured');

// --- generateForItem(): successful generation with title and description ---
[$generator, $client] = makeGenerator(fn () => [
    'title' => 'Uchwała w sprawie zmiany planu studiów',
    'description' => 'Uchwała dotycząca zmiany planu studiów na kierunku Finanse.',
]);
$result = $generator->generateForItem('31-2026-uchwala.docx', 'tresc dokumentu');
assertTrue($result !== null, 'A well-formed response must produce a non-null result');
assertTrue($result['title'] === 'Uchwała w sprawie zmiany planu studiów', 'title must be passed through');
assertTrue($result['description'] === 'Uchwała dotycząca zmiany planu studiów na kierunku Finanse.', 'description must be passed through');

$call = $client->calls[0];
assertTrue($call['apiKey'] === 'sk-test-key', 'The configured API key must be passed to the client');
assertTrue($call['model'] === 'gpt-4o-mini', 'The configured model must be passed to the client');
assertTrue(count($call['messages']) === 2, 'Must send exactly a system and a user message');
assertTrue($call['messages'][0]['role'] === 'system', 'First message must be the system prompt');
assertTrue($call['messages'][1]['role'] === 'user', 'Second message must be the user prompt');
assertTrue(str_contains($call['messages'][1]['content'], '31-2026-uchwala.docx'), 'The user prompt must include the filename');
assertTrue(str_contains($call['messages'][1]['content'], 'tresc dokumentu'), 'The user prompt must include the extracted text');

// --- generateForItem(): missing description defaults to empty string, not an error ---
[$generator] = makeGenerator(fn () => ['title' => 'Tylko tytuł']);
$result = $generator->generateForItem('a.pdf', 't');
assertTrue($result !== null, 'A response with no description field must still be usable');
assertTrue($result['description'] === '', 'A missing description field must default to an empty string');

// --- generateForItem(): missing title must be treated as unusable ---
[$generator] = makeGenerator(fn () => ['description' => 'x']);
$result = $generator->generateForItem('a.pdf', 't');
assertTrue($result === null, 'A response with no title must be treated as invalid/unusable');

// --- generateForItem(): client returns null (network error / timeout / malformed JSON) propagates as null ---
[$generator] = makeGenerator(fn () => null);
$result = $generator->generateForItem('a.pdf', 't');
assertTrue($result === null, 'A null client response must propagate as null');

// --- generateForItemFromImage(): unconfigured must return null immediately, without calling the client ---
[$generator, $client] = makeGenerator(fn () => throw new RuntimeException('Must not be called when unconfigured.'), ['openAiApiKey' => '', 'openAiModel' => '']);
$result = $generator->generateForItemFromImage('a.pdf', base64_encode('fake png bytes'));
assertTrue($result === null, 'generateForItemFromImage() must return null immediately when unconfigured');
assertTrue(count($client->calls) === 0, 'The HTTP client must never be invoked when unconfigured');

// --- generateForItemFromImage(): empty image data must return null immediately, without calling the client ---
[$generator, $client] = makeGenerator(fn () => throw new RuntimeException('Must not be called with no image.'));
$result = $generator->generateForItemFromImage('a.pdf', '');
assertTrue($result === null, 'generateForItemFromImage() must return null immediately when given no image data');
assertTrue(count($client->calls) === 0, 'The HTTP client must never be invoked with no image data');

// --- generateForItemFromImage(): successful generation with title and description ---
[$generator, $client] = makeGenerator(fn () => [
    'title' => 'Uchwała Samorządu Studentów w sprawie wyboru przewodniczącego',
    'description' => 'Skan uchwały samorządu studentów dotyczącej wyboru przewodniczącego.',
]);
$imageBase64 = base64_encode('fake png bytes');
$result = $generator->generateForItemFromImage('uchwala-samorzadu-studentow-27.pdf', $imageBase64);
assertTrue($result !== null, 'A well-formed response must produce a non-null result');
assertTrue($result['title'] === 'Uchwała Samorządu Studentów w sprawie wyboru przewodniczącego', 'title must be passed through');
assertTrue($result['description'] === 'Skan uchwały samorządu studentów dotyczącej wyboru przewodniczącego.', 'description must be passed through');

$call = $client->calls[0];
assertTrue($call['apiKey'] === 'sk-test-key', 'The configured API key must be passed to the client');
assertTrue(count($call['messages']) === 2, 'Must send exactly a system and a user message');
assertTrue($call['messages'][0]['role'] === 'system', 'First message must be the system prompt');
$userContent = $call['messages'][1]['content'];
assertTrue(is_array($userContent), 'The user message content must be a multi-part array (text + image), not a plain string');
assertTrue($userContent[0]['type'] === 'text', 'First content part must be the text part');
assertTrue(str_contains($userContent[0]['text'], 'uchwala-samorzadu-studentow-27.pdf'), 'The text part must include the filename');
assertTrue($userContent[1]['type'] === 'image_url', 'Second content part must be the image part');
assertTrue($userContent[1]['image_url']['url'] === 'data:image/png;base64,' . $imageBase64, 'The image part must be a base64 PNG data URI with the given bytes');

// --- generateForItemFromImage(): missing title must be treated as unusable ---
[$generator] = makeGenerator(fn () => ['description' => 'x']);
$result = $generator->generateForItemFromImage('a.pdf', base64_encode('x'));
assertTrue($result === null, 'A response with no title must be treated as invalid/unusable');

// --- generateForItemFromImage(): client returns null (network error / timeout / malformed JSON) propagates as null ---
[$generator] = makeGenerator(fn () => null);
$result = $generator->generateForItemFromImage('a.pdf', base64_encode('x'));
assertTrue($result === null, 'A null client response must propagate as null');

echo sprintf("%d OpenAiTitleGenerator assertions passed.\n", $assertions);
