<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\SlugCandidateBuilder;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/SlugCandidateBuilder.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

assertTrue(
    SlugCandidateBuilder::build('Uchwała Senatu nr 5') === 'uchwala-senatu-nr-5',
    'Polish diacritics must be transliterated and spaces collapsed to single dashes'
);
assertTrue(
    SlugCandidateBuilder::build('Żółć Łódź Ćma Śnieg Źrebię Ńatura') === 'zolc-lodz-cma-snieg-zrebie-natura',
    'All nine Polish diacritic characters (lower and upper case) must transliterate correctly'
);
assertTrue(
    SlugCandidateBuilder::build('  --Multiple---Dashes--  ') === 'multiple-dashes',
    'Repeated separators and surrounding punctuation must collapse and trim to a clean slug'
);
assertTrue(
    SlugCandidateBuilder::build('') === 'dokument',
    'An empty title must fall back to a safe default slug'
);
assertTrue(
    SlugCandidateBuilder::build('!!!???') === 'dokument',
    'A title with no alphanumeric content must fall back to the safe default'
);

echo sprintf("%d SlugCandidateBuilder assertions passed.\n", $assertions);
