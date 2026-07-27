<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Derives a subtitle candidate from the "w sprawie ..." clause common to uchwała/zarządzenie
 * titles. Descriptive program_studiow titles rarely split this way, so no candidate is
 * derived for that type.
 */
final class SubtitleDeriver
{
    private const SUBTITLE_TYPES = [
        'uchwala',
        'zarzadzenie', // legacy value, kept for sets classified before the rektor/prezydent split
        'zarzadzenie_rektora',
        'zarzadzenie_prezydenta',
        'zarzadzenie_prezydenta_i_rektora',
    ];

    public static function derive(string $text, string $typeSuggestion): ?string
    {
        if (!in_array($typeSuggestion, self::SUBTITLE_TYPES, true)) {
            return null;
        }

        return self::extractClause($text);
    }

    /**
     * Extracts the "w sprawie ..." clause regardless of type, for use as a raw title/subtitle
     * text-candidate signal. Callers that only want a set-level subtitle should use derive()
     * instead, which additionally gates on the suggested type.
     */
    public static function extractClause(string $text): ?string
    {
        if (preg_match('/w\s+sprawie\s+(.+?)(?:[.\n]|$)/iu', $text, $matches) !== 1) {
            return null;
        }

        $clause = trim($matches[1]);

        return $clause !== '' ? $clause : null;
    }
}
