<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Builds a short "type-number-year" slug candidate (e.g. "uchwala-31-2026") straight from a
 * document's identifier line ("... NR 31/2026 ... z dnia 25 czerwca 2026 r. ..."), instead of
 * transliterating the full (often long) descriptive title. Used as a heuristic-only counterpart
 * to the AI-generated slug, so a short slug is still produced when no API key is configured.
 *
 * The page-slug prefix is shared across all zarzadzenie_* sub-types (issuing authority doesn't
 * change the URL shape); the auto-generated FAL subfolder name (buildNumberYear()) does encode
 * the authority, since that's the whole point of splitting the type in the first place - see
 * FOLDER_PREFIXES.
 */
final class IdentifierSlugBuilder
{
    private const TYPE_SLUGS = [
        'uchwala' => 'uchwala',
        'zarzadzenie' => 'zarzadzenie', // legacy value, kept for sets classified before the split
        'zarzadzenie_rektora' => 'zarzadzenie',
        'zarzadzenie_prezydenta' => 'zarzadzenie',
        'zarzadzenie_prezydenta_i_rektora' => 'zarzadzenie',
    ];

    private const FOLDER_PREFIXES = [
        'uchwala' => '',
        'zarzadzenie' => 'rektor-', // legacy value defaults to the same authority as the base type below
        'zarzadzenie_rektora' => 'rektor-',
        'zarzadzenie_prezydenta' => 'prezydent-',
        'zarzadzenie_prezydenta_i_rektora' => 'prezydent-rektor-',
    ];

    /**
     * @return string|null null if the type isn't slug-able (e.g. program_studiow, unknown) or
     *         no "nr X" + a 4-digit year could both be found in the text
     */
    public static function build(string $type, string $text): ?string
    {
        $typeSlug = self::TYPE_SLUGS[$type] ?? null;
        $components = self::extractNumberAndYear($type, $text);
        if ($typeSlug === null || $components === null) {
            return null;
        }

        return sprintf('%s-%s-%s', $typeSlug, $components[0], $components[1]);
    }

    /**
     * Same "nr X" + year extraction as build(), concatenated without a type-slug prefix (e.g.
     * "312026" for uchwała, "rektor-312026" / "prezydent-312026" / "prezydent-rektor-312026" for
     * the zarządzenie sub-types) - used as an auto-generated FAL destination subfolder name, so
     * a set's files land in a per-document-number folder rather than one flat folder shared by
     * every uchwała/zarządzenie ever confirmed.
     *
     * @return string|null null under the same conditions as build()
     */
    public static function buildNumberYear(string $type, string $text): ?string
    {
        $components = self::extractNumberAndYear($type, $text);
        if ($components === null) {
            return null;
        }

        return (self::FOLDER_PREFIXES[$type] ?? '') . $components[0] . $components[1];
    }

    /**
     * @return array{0: string, 1: string}|null [number, year] or null if the type isn't
     *         slug-able or no "nr X" + a 4-digit year could both be found in the text
     */
    private static function extractNumberAndYear(string $type, string $text): ?array
    {
        if (!isset(self::TYPE_SLUGS[$type])) {
            return null;
        }

        if (preg_match('/\bnr\.?\s*(\d+)/iu', $text, $numberMatch) !== 1) {
            return null;
        }

        if (preg_match('/\b(20\d{2})\b/u', $text, $yearMatch) !== 1) {
            return null;
        }

        return [$numberMatch[1], $yearMatch[1]];
    }
}
