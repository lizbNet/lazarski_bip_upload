<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Turns a raw uploaded filename into a plausible human-readable title candidate:
 * strips the extension, decodes percent-encoding, and normalizes separators/whitespace.
 */
final class FilenameNormalizer
{
    public static function normalize(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $decoded = rawurldecode($name);
        if ($decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
            $name = $decoded;
        }

        $name = str_replace(['_', '-', '+'], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}
