<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

use Smalot\PdfParser\Parser;

/**
 * Reads PDF Info-dictionary metadata and leading extracted text. Kept as a thin,
 * dependency-facing wrapper - all ranking/classification decisions live in the pure
 * Analysis\* classes, not here.
 */
class PdfMetadataReader
{
    private const TEXT_LINE_LIMIT = 40;

    /**
     * @return array{title: string, text: string}
     */
    public function read(string $absolutePath): array
    {
        $document = (new Parser())->parseFile($absolutePath);
        $details = $document->getDetails();

        $lines = preg_split('/\r\n|\r|\n/', $document->getText(1)) ?: [];
        $leadingText = implode("\n", array_slice($lines, 0, self::TEXT_LINE_LIMIT));

        return [
            'title' => trim((string)($details['Title'] ?? '')),
            'text' => trim($leadingText),
        ];
    }
}
