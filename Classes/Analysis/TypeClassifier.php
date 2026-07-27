<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Classifies a document set among the supported Polish institutional document types by
 * matching filename/text signals against real structural patterns of each type. Never forces
 * a guess: no signal found means type '' at confidence 0.
 *
 * "zarzadzenie" is further split by issuing authority - zarzadzenie_rektora,
 * zarzadzenie_prezydenta, or zarzadzenie_prezydenta_i_rektora when both are mentioned -
 * defaulting to zarzadzenie_rektora when the "zarządzenie" pattern matches but neither
 * authority keyword is found (matches this institution's most common case).
 */
final class TypeClassifier
{
    private const PATTERNS = [
        'uchwala' => '/uchwal\w*/u',
        'zarzadzenie' => '/zarzadzeni\w*/u',
        'program_studiow' => '/(program\w*\s+studi\w*|plan\w*\s+studi\w*)/u',
    ];

    private const BOOST_PATTERNS = [
        'uchwala' => '/(\bnr\.?\s*\d+|\bsenat\w*|\brad\w*)/u',
        'program_studiow' => '/efekt\w*\s+uczeni/u',
    ];

    private const PREZYDENT_PATTERN = '/\bprezydent\w*/u';
    private const REKTOR_PATTERN = '/\brektor\w*/u';

    private const BASE_CONFIDENCE = 65;
    private const BOOSTED_CONFIDENCE = 90;

    public static function classify(string ...$texts): TypeSuggestion
    {
        $folded = self::foldDiacritics(mb_strtolower(implode(' ', $texts), 'UTF-8'));

        foreach (self::PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $folded) !== 1) {
                continue;
            }

            if ($type === 'zarzadzenie') {
                return self::classifyZarzadzenie($folded);
            }

            $confidence = self::BASE_CONFIDENCE;
            if (isset(self::BOOST_PATTERNS[$type]) && preg_match(self::BOOST_PATTERNS[$type], $folded) === 1) {
                $confidence = self::BOOSTED_CONFIDENCE;
            }

            return new TypeSuggestion(
                $type,
                $confidence,
                sprintf('Matched "%s" naming pattern in the document text.', $type)
            );
        }

        return new TypeSuggestion('', 0, 'No recognizable Polish document-type signal was found.');
    }

    private static function classifyZarzadzenie(string $folded): TypeSuggestion
    {
        $hasPrezydent = preg_match(self::PREZYDENT_PATTERN, $folded) === 1;
        $hasRektor = preg_match(self::REKTOR_PATTERN, $folded) === 1;

        if ($hasPrezydent && $hasRektor) {
            return new TypeSuggestion(
                'zarzadzenie_prezydenta_i_rektora',
                self::BOOSTED_CONFIDENCE,
                'Matched "zarzadzenie" naming pattern, issued jointly by the Prezydent and Rektor.'
            );
        }
        if ($hasPrezydent) {
            return new TypeSuggestion(
                'zarzadzenie_prezydenta',
                self::BOOSTED_CONFIDENCE,
                'Matched "zarzadzenie" naming pattern, issued by the Prezydent.'
            );
        }
        if ($hasRektor) {
            return new TypeSuggestion(
                'zarzadzenie_rektora',
                self::BOOSTED_CONFIDENCE,
                'Matched "zarzadzenie" naming pattern, issued by the Rektor.'
            );
        }

        return new TypeSuggestion(
            'zarzadzenie_rektora',
            self::BASE_CONFIDENCE,
            'Matched "zarzadzenie" naming pattern; no issuing authority keyword found, defaulting to Rektor.'
        );
    }

    private static function foldDiacritics(string $text): string
    {
        static $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ];

        return strtr($text, $map);
    }
}
