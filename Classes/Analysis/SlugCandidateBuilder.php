<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Builds a raw slug candidate from a title via Polish-diacritic transliteration. This is only
 * a candidate - final uniqueness/collision handling under the chosen parent page is Step 3/4's
 * job via TYPO3's own SlugHelper.
 */
final class SlugCandidateBuilder
{
    private const TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
        'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
    ];

    public static function build(string $title): string
    {
        $transliterated = strtr($title, self::TRANSLITERATION);
        $lower = mb_strtolower($transliterated, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $lower) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'dokument';
    }
}
