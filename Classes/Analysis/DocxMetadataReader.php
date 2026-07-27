<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

use PhpOffice\PhpWord\IOFactory;

/**
 * Reads DOCX core properties and heading text. Kept as a thin, dependency-facing wrapper -
 * all ranking/classification decisions live in the pure Analysis\* classes, not here.
 *
 * Headings are read directly from the raw word/document.xml rather than via PhpWord's
 * reconstructed element tree: PhpWord's reader does not reconstruct Title/heading objects
 * from styled paragraphs on load (that class only matters when programmatically writing a
 * document) - a paragraph's original "Heading1" etc. style reference only survives as the
 * w:pStyle value in the XML itself.
 */
class DocxMetadataReader
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{title: string, subject: string, headings: string[]}
     */
    public function read(string $absolutePath): array
    {
        $docInfo = IOFactory::load($absolutePath)->getDocInfo();

        return [
            'title' => trim((string)$docInfo->getTitle()),
            'subject' => trim((string)$docInfo->getSubject()),
            'headings' => $this->readHeadings($absolutePath),
        ];
    }

    /**
     * @return string[]
     */
    private function readHeadings(string $absolutePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($documentXml === false) {
            return [];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($documentXml);
        libxml_use_internal_errors($previousUseErrors);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('w', self::WORD_NAMESPACE);

        $headings = [];
        foreach ($xml->xpath('//w:body/w:p') as $paragraph) {
            $paragraph->registerXPathNamespace('w', self::WORD_NAMESPACE);
            $styleNodes = $paragraph->xpath('.//w:pStyle/@w:val');
            $styleValue = isset($styleNodes[0]) ? (string)$styleNodes[0] : '';
            if (preg_match('/^Heading\d*$/i', $styleValue) !== 1) {
                continue;
            }

            $textNodes = $paragraph->xpath('.//w:t');
            $text = trim(implode('', array_map(static fn ($node): string => (string)$node, $textNodes)));
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }
}
