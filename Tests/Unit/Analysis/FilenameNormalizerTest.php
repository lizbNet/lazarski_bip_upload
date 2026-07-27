<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\FilenameNormalizer;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/FilenameNormalizer.php';

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
    FilenameNormalizer::normalize('Uchwala_nr_5_2024.docx') === 'Uchwala nr 5 2024',
    'Underscores must become spaces and the extension must be stripped'
);
assertTrue(
    FilenameNormalizer::normalize('program-studiow--informatyka.pdf') === 'program studiow informatyka',
    'Dashes must become spaces and repeated separators must collapse to one space'
);
assertTrue(
    FilenameNormalizer::normalize('Uchwa%C5%82a%20Senatu.docx') === 'Uchwała Senatu',
    'Percent-encoded filenames must be decoded'
);
assertTrue(
    FilenameNormalizer::normalize('  spacey   name  .pdf') === 'spacey name',
    'Leading/trailing whitespace must be trimmed and internal whitespace collapsed'
);
assertTrue(
    FilenameNormalizer::normalize('no_extension') === 'no extension',
    'A filename without an extension must still normalize'
);
assertTrue(
    FilenameNormalizer::normalize('') === '',
    'An empty filename must normalize to an empty string, not error'
);

echo sprintf("%d FilenameNormalizer assertions passed.\n", $assertions);
