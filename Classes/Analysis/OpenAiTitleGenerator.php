<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Generates a cleaned-up page title, subtitle, and per-file titles via an LLM (OpenAI Chat
 * Completions), specifically to catch cases the deterministic heuristic can't: metadata that
 * LOOKS like a title field (so it wins the confidence-based ranking) but is actually scanner-
 * generated junk (a timestamp, a generic "Scan_0001" string, ...).
 *
 * Always attempted first when an API key is configured (per-set, one call for the whole set
 * covering the page title/subtitle and every item's title, not one call per file). Any failure
 * - missing key, network error, timeout, malformed response - returns null and the caller must
 * fall back to the deterministic Candidate-based heuristic; this is never a hard requirement.
 */
class OpenAiTitleGenerator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const EXTENSION_KEY = 'lazarski_bip_upload';
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const MAX_TEXT_LENGTH_PER_ITEM = 3000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Jesteś asystentem redakcyjnym Biuletynu Informacji Publicznej polskiej uczelni. Otrzymujesz
        dane o zestawie plików (uchwała, zarządzenie lub program studiów) - nazwy plików oraz
        wyekstrahowany tekst/metadane każdego z nich. Metadane pochodzące ze skanera (np. sama data
        i godzina, "Scan0001", puste/losowe ciągi) NIE są prawdziwym tytułem - w takim przypadku
        zignoruj je i wywnioskuj tytuł z pozostałych sygnałów (nazwa pliku, treść innych plików w
        zestawie).

        WAŻNE rozróżnienie tytułu i podtytułu dla uchwał/zarządzeń:
        - "pageTitle" to MERYTORYCZNA treść dokumentu - czego on dotyczy (klauzula "w sprawie ..."),
          poprzedzona słowem określającym typ dokumentu, np. "Uchwała w sprawie ...", "Zarządzenie
          w sprawie ...". To jest główny tytuł widoczny na stronie.
        - "subtitle" to FORMALNY identyfikator dokumentu - numer, organ wydający i data, np.
          "Uchwała nr 12/2025 Senatu Uczelni Łazarskiego z dnia 10 maja 2025 r.". NIE opisuj tu
          treści dokumentu (to należy wyłącznie do "pageTitle").
        - Ta sama zasada dotyczy "itemTitles": każdy tytuł pliku powinien być merytoryczny (jak
          "pageTitle"), a nie samym numerem/identyfikatorem.
        - Dla "program studiów" (opisowe tytuły, zwykle bez klauzuli "w sprawie" i bez formalnego
          numeru uchwały) zostaw "subtitle" jako pusty string, jeśli nie ma osobnego identyfikatora
          do wyodrębnienia.

        "slug" to KRÓTKI adres URL, ZAWSZE zbudowany z typu dokumentu, numeru i roku (NIGDY z
        merytorycznej treści tytułu) - format "typ-numer-rok", np. "uchwala-12-2025". Same małe
        litery łacińskie, cyfry i myślniki, bez polskich znaków diakrytycznych, bez spacji. Jeśli
        nie da się wyodrębnić numeru/roku (np. dla "program studiów"), zbuduj krótki 2-4-wyrazowy
        slug z najważniejszych słów tytułu zamiast pełnego zdania.

        Przykład - dokument zawiera tekst: "UCHWAŁA NR 12/2025 SENATU UCZELNI ŁAZARSKIEGO z dnia 10
        maja 2025 r. w sprawie zmiany regulaminu studiów". Poprawna odpowiedź:
        {"pageTitle": "Uchwała w sprawie zmiany regulaminu studiów", "subtitle": "Uchwała nr 12/2025
        Senatu Uczelni Łazarskiego z dnia 10 maja 2025 r.", "slug": "uchwala-12-2025",
        "itemTitles": ["Uchwała w sprawie zmiany regulaminu studiów"]}

        Odpowiedz WYŁĄCZNIE obiektem JSON o polach: "pageTitle", "subtitle", "slug" oraz
        "itemTitles" (tablica tytułów, dokładnie jeden na każdy plik wejściowy, w tej samej
        kolejności). Wszystko w języku polskim, bez dodatkowego tekstu poza obiektem JSON.
        PROMPT;

    private const ITEM_SYSTEM_PROMPT = <<<'PROMPT'
        Jesteś asystentem redakcyjnym Biuletynu Informacji Publicznej polskiej uczelni. Otrzymujesz
        nazwę pliku oraz wyekstrahowany tekst/metadane JEDNEGO dokumentu (uchwała, zarządzenie,
        program studiów lub załącznik do innego dokumentu). Metadane pochodzące ze skanera (np.
        sama data i godzina, "Scan0001", puste/losowe ciągi) NIE są prawdziwym tytułem - w takim
        przypadku wywnioskuj tytuł z nazwy pliku zamiast z tych metadanych.

        Wygeneruj dla TEGO PLIKU:
        - "title": merytoryczny tytuł - dla uchwały/zarządzenia w formie "Uchwała w sprawie ...",
          "Zarządzenie w sprawie ..." (czego dokument dotyczy, NIE sam numer/identyfikator); dla
          innych plików (załącznik, program studiów) krótki, opisowy tytuł tego, co zawiera plik.
        - "description": krótki, 1-2 zdaniowy opis zawartości pliku w języku polskim, przydatny
          np. jako etykieta dostępności (czytniki ekranu) przy linku do pobrania. Pusty string,
          jeśli nie da się nic sensownego wywnioskować z dostępnego tekstu.

        Odpowiedz WYŁĄCZNIE obiektem JSON o polach "title" oraz "description". Wszystko w języku
        polskim, bez dodatkowego tekstu poza obiektem JSON.
        PROMPT;

    private const ITEM_SYSTEM_PROMPT_IMAGE = <<<'PROMPT'
        Jesteś asystentem redakcyjnym Biuletynu Informacji Publicznej polskiej uczelni. Otrzymujesz
        nazwę pliku oraz obraz pierwszej strony ZESKANOWANEGO dokumentu (uchwała, zarządzenie,
        program studiów lub załącznik do innego dokumentu) - ten skan nie ma warstwy tekstowej, więc
        musisz samodzielnie odczytać widoczny na obrazie tekst.

        Wygeneruj dla TEGO PLIKU:
        - "title": merytoryczny tytuł - dla uchwały/zarządzenia w formie "Uchwała w sprawie ...",
          "Zarządzenie w sprawie ..." (czego dokument dotyczy, NIE sam numer/identyfikator); dla
          innych plików (załącznik, program studiów) krótki, opisowy tytuł tego, co zawiera plik.
        - "description": krótki, 1-2 zdaniowy opis zawartości pliku w języku polskim, przydatny
          np. jako etykieta dostępności (czytniki ekranu) przy linku do pobrania. Pusty string,
          jeśli obraz jest nieczytelny lub nie da się nic sensownego odczytać.

        Odpowiedz WYŁĄCZNIE obiektem JSON o polach "title" oraz "description". Wszystko w języku
        polskim, bez dodatkowego tekstu poza obiektem JSON.
        PROMPT;

    public function __construct(
        private readonly OpenAiClientInterface $client,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '';
    }

    /**
     * @param array<int, array{filename: string, extractedText: string}> $items ordered list
     * @return array{pageTitle: string, subtitle: string, slug: string, itemTitles: string[]}|null
     */
    public function generate(string $type, array $items): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '' || $items === []) {
            $this->logger?->debug('generate() skipped: not configured or no items', [
                'configured' => $apiKey !== '',
                'itemCount' => count($items),
            ]);

            return null;
        }

        $decoded = $this->client->chatCompletionJson(
            $apiKey,
            $this->getModel(),
            [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $this->buildUserPrompt($type, $items)],
            ],
            0.2
        );

        $result = self::parseResponse($decoded, count($items));
        $this->logger?->debug('generate() result', [
            'type' => $type,
            'filenames' => array_column($items, 'filename'),
            'decoded' => $decoded,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Generates a title and short description for a single file, independent of the rest of
     * its set - a manually-triggered, per-file counterpart to generate(), for re-running AI
     * assistance on just one file (e.g. after generate() was skipped because no key was
     * configured yet, or to refresh one file without touching the others).
     *
     * @return array{title: string, description: string}|null
     */
    public function generateForItem(string $filename, string $extractedText): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            $this->logger?->debug('generateForItem() skipped: not configured', ['filename' => $filename]);

            return null;
        }

        $decoded = $this->client->chatCompletionJson(
            $apiKey,
            $this->getModel(),
            [
                ['role' => 'system', 'content' => self::ITEM_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => sprintf(
                    "Nazwa pliku: %s\nWyekstrahowany tekst/metadane:\n%s",
                    $filename,
                    mb_substr(trim($extractedText), 0, self::MAX_TEXT_LENGTH_PER_ITEM)
                )],
            ],
            0.2
        );

        $result = self::parseItemResponse($decoded);
        $this->logger?->debug('generateForItem() result', [
            'filename' => $filename,
            'extractedTextLength' => mb_strlen($extractedText),
            'decoded' => $decoded,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Vision counterpart to generateForItem(), for image-only PDFs with no extractable text
     * (unOCR'd office scans): sends the rasterized first page as an image instead of empty
     * text, so the model reads the visible text itself rather than being given nothing to work
     * with. Same JSON contract/return shape as generateForItem().
     *
     * @return array{title: string, description: string}|null
     */
    public function generateForItemFromImage(string $filename, string $imagePngBase64): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '' || $imagePngBase64 === '') {
            $this->logger?->debug('generateForItemFromImage() skipped: not configured or no image', [
                'filename' => $filename,
                'configured' => $apiKey !== '',
                'hasImage' => $imagePngBase64 !== '',
            ]);

            return null;
        }

        $decoded = $this->client->chatCompletionJson(
            $apiKey,
            $this->getModel(),
            [
                ['role' => 'system', 'content' => self::ITEM_SYSTEM_PROMPT_IMAGE],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => sprintf('Nazwa pliku: %s', $filename)],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:image/png;base64,' . $imagePngBase64,
                    ]],
                ]],
            ],
            0.2
        );

        $result = self::parseItemResponse($decoded);
        $this->logger?->debug('generateForItemFromImage() result', [
            'filename' => $filename,
            'decoded' => $decoded,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @return array{title: string, description: string}|null
     */
    private static function parseItemResponse(?array $decoded): ?array
    {
        if ($decoded === null) {
            return null;
        }

        $title = trim((string)($decoded['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'description' => trim((string)($decoded['description'] ?? '')),
        ];
    }

    /**
     * @param array<int, array{filename: string, extractedText: string}> $items
     */
    private function buildUserPrompt(string $type, array $items): string
    {
        $lines = [sprintf('Typ dokumentu (sugerowany automatycznie): %s', $type !== '' ? $type : 'nieznany')];

        foreach ($items as $index => $item) {
            $lines[] = sprintf(
                "\nPlik %d: %s\nWyekstrahowany tekst/metadane:\n%s",
                $index + 1,
                $item['filename'],
                mb_substr(trim($item['extractedText']), 0, self::MAX_TEXT_LENGTH_PER_ITEM)
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @return array{pageTitle: string, subtitle: string, slug: string, itemTitles: string[]}|null
     */
    private static function parseResponse(?array $decoded, int $expectedItemCount): ?array
    {
        if ($decoded === null) {
            return null;
        }

        $pageTitle = trim((string)($decoded['pageTitle'] ?? ''));
        if ($pageTitle === '') {
            return null;
        }

        $subtitle = trim((string)($decoded['subtitle'] ?? ''));

        $rawSlug = trim((string)($decoded['slug'] ?? ''));
        // Sanitized regardless of what the model returned: the instructions ask for a clean
        // lowercase/hyphenated slug, but this must never be trusted blindly for something that
        // becomes part of a URL.
        $slug = $rawSlug !== '' ? SlugCandidateBuilder::build($rawSlug) : '';

        $rawItemTitles = $decoded['itemTitles'] ?? [];
        $itemTitles = [];
        for ($i = 0; $i < $expectedItemCount; $i++) {
            $itemTitles[] = is_array($rawItemTitles) && isset($rawItemTitles[$i])
                ? trim((string)$rawItemTitles[$i])
                : '';
        }

        return [
            'pageTitle' => $pageTitle,
            'subtitle' => $subtitle,
            'slug' => $slug,
            'itemTitles' => $itemTitles,
        ];
    }

    private function getApiKey(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'openAiApiKey'));
        } catch (\Exception $exception) {
            return '';
        }
    }

    private function getModel(): string
    {
        try {
            $model = trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'openAiModel'));
        } catch (\Exception $exception) {
            $model = '';
        }

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }
}
