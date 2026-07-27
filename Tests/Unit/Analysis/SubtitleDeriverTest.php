<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\SubtitleDeriver;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/SubtitleDeriver.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- derives a subtitle for uchwala/zarzadzenie when the clause is present ---
assertTrue(
    SubtitleDeriver::derive('Uchwała Senatu w sprawie zmian w regulaminie studiów.', 'uchwala') === 'zmian w regulaminie studiów',
    'Must extract the text after "w sprawie" up to the sentence boundary'
);
assertTrue(
    SubtitleDeriver::derive("Zarządzenie Rektora W SPRAWIE organizacji roku\ndalszy tekst", 'zarzadzenie') === 'organizacji roku',
    'Matching must be case-insensitive and stop at a newline (legacy zarzadzenie value)'
);
assertTrue(
    SubtitleDeriver::derive('Zarządzenie Rektora w sprawie organizacji roku.', 'zarzadzenie_rektora') === 'organizacji roku',
    'Must also work for the zarzadzenie_rektora sub-type'
);
assertTrue(
    SubtitleDeriver::derive('Zarządzenie Prezydenta w sprawie oplat.', 'zarzadzenie_prezydenta') === 'oplat',
    'Must also work for the zarzadzenie_prezydenta sub-type'
);
assertTrue(
    SubtitleDeriver::derive('Zarządzenie Prezydenta i Rektora w sprawie czegos.', 'zarzadzenie_prezydenta_i_rektora') === 'czegos',
    'Must also work for the zarzadzenie_prezydenta_i_rektora sub-type'
);

// --- no candidate for program_studiow (descriptive titles rarely split this way) ---
assertTrue(
    SubtitleDeriver::derive('Program studiów w sprawie czegokolwiek.', 'program_studiow') === null,
    'program_studiow must never produce a subtitle candidate, regardless of text content'
);

// --- no candidate when the type is unknown ---
assertTrue(
    SubtitleDeriver::derive('w sprawie czegos.', '') === null,
    'An unknown/empty type must not produce a subtitle candidate'
);

// --- no "w sprawie" clause present ---
assertTrue(
    SubtitleDeriver::derive('Uchwała Senatu bez klauzuli.', 'uchwala') === null,
    'Must return null when no "w sprawie" clause exists in the text'
);

// --- extractClause() is type-agnostic ---
assertTrue(
    SubtitleDeriver::extractClause('Dokument w sprawie płatności.') === 'płatności',
    'extractClause() must find the clause regardless of any type gate'
);
assertTrue(
    SubtitleDeriver::extractClause('brak klauzuli tutaj') === null,
    'extractClause() must return null when no clause is present'
);

echo sprintf("%d SubtitleDeriver assertions passed.\n", $assertions);
