<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\TypeClassifier;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/TypeSuggestion.php';
require_once dirname(__DIR__, 3) . '/Classes/Analysis/TypeClassifier.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- uchwała, boosted by "nr" + "senatu" ---
$result = TypeClassifier::classify('Uchwała nr 15 2024 Senatu Uczelni w sprawie zmian');
assertTrue($result->type === 'uchwala', 'Must classify as uchwala');
assertTrue($result->confidence === 90, 'Boost pattern (nr + senat) must raise confidence to 90');

// --- uchwała without boost signals, lower confidence ---
$result = TypeClassifier::classify('uchwala o zmianie regulaminu');
assertTrue($result->type === 'uchwala', 'Must still classify as uchwala without boost words');
assertTrue($result->confidence === 65, 'No boost pattern must leave confidence at base level (65)');

// --- zarządzenie Rektora: split by issuing authority ---
$result = TypeClassifier::classify('Zarzadzenie Rektora ws. organizacji roku akademickiego');
assertTrue($result->type === 'zarzadzenie_rektora', 'Must classify as zarzadzenie_rektora when "rektor" is mentioned');
assertTrue($result->confidence === 90, 'A recognized issuing authority must raise confidence to 90');

// --- zarządzenie Prezydenta ---
$result = TypeClassifier::classify('Zarzadzenie Prezydenta Uczelni Lazarskiego ws. oplat');
assertTrue($result->type === 'zarzadzenie_prezydenta', 'Must classify as zarzadzenie_prezydenta when only "prezydent" is mentioned');
assertTrue($result->confidence === 90, 'A recognized issuing authority must raise confidence to 90');

// --- zarządzenie Prezydenta i Rektora: both authorities mentioned ---
$result = TypeClassifier::classify('Zarzadzenie Prezydenta i Rektora Uczelni Lazarskiego ws. czegos');
assertTrue($result->type === 'zarzadzenie_prezydenta_i_rektora', 'Must classify as the joint sub-type when both authorities are mentioned');
assertTrue($result->confidence === 90, 'Both authorities mentioned must also count as a confidence boost');

// --- zarządzenie with no issuing authority keyword at all: defaults to Rektora, base confidence ---
$result = TypeClassifier::classify('Zarzadzenie w sprawie czegos bez wskazania organu');
assertTrue($result->type === 'zarzadzenie_rektora', 'No issuing authority keyword must default to zarzadzenie_rektora');
assertTrue($result->confidence === 65, 'No issuing authority keyword must leave confidence at base level (65)');

// --- program studiów, no "nr" required ---
$result = TypeClassifier::classify('Program studiow kierunek Informatyka 2024');
assertTrue($result->type === 'program_studiow', 'Must classify as program_studiow');

// --- diacritic-folded matching: "uchwała" (real diacritics) must match the same as "uchwala" ---
$result = TypeClassifier::classify('UCHWAŁA Senatu nr 3');
assertTrue($result->type === 'uchwala', 'Diacritics and case must be folded before matching');
assertTrue($result->confidence === 90, 'Boost detection must also work after diacritic folding');

// --- no signal at all: never force a guess ---
$result = TypeClassifier::classify('raport finansowy za rok 2023');
assertTrue($result->type === '', 'No recognizable signal must yield an empty (unknown) type');
assertTrue($result->confidence === 0, 'Unknown type must carry zero confidence');

// --- multiple text sources combined (filename + extracted text) ---
$result = TypeClassifier::classify('dokument.docx', 'w sprawie planu studiow drugiego stopnia');
assertTrue($result->type === 'program_studiow', 'Signal in a later text argument must still be found');

echo sprintf("%d TypeClassifier assertions passed.\n", $assertions);
