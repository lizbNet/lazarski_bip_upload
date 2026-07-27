<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\IdentifierSlugBuilder;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/IdentifierSlugBuilder.php';

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
    IdentifierSlugBuilder::build('uchwala', 'UCHWAŁA NR 31/2026 SENATU UCZELNI ŁAZARSKIEGO z dnia 25 czerwca 2026 r. w sprawie ...') === 'uchwala-31-2026',
    'Must extract a short "type-number-year" slug from a real identifier line'
);
assertTrue(
    IdentifierSlugBuilder::build('zarzadzenie', 'Zarządzenie nr 7/2024 Rektora z dnia 3 stycznia 2024 r.') === 'zarzadzenie-7-2024',
    'Must work for the legacy zarzadzenie value too, with a lowercase "nr"'
);
assertTrue(
    IdentifierSlugBuilder::build('zarzadzenie_rektora', 'Zarządzenie nr 7/2024 Rektora z dnia 3 stycznia 2024 r.') === 'zarzadzenie-7-2024',
    'zarzadzenie_rektora must produce the same shared "zarzadzenie" page-slug prefix'
);
assertTrue(
    IdentifierSlugBuilder::build('zarzadzenie_prezydenta', 'Zarządzenie nr 9/2025 Prezydenta z dnia 1 lutego 2025 r.') === 'zarzadzenie-9-2025',
    'zarzadzenie_prezydenta must also produce the shared "zarzadzenie" page-slug prefix (issuer doesn\'t change the URL shape)'
);
assertTrue(
    IdentifierSlugBuilder::build('zarzadzenie_prezydenta_i_rektora', 'Zarządzenie nr 3/2025 Prezydenta i Rektora z dnia 1 marca 2025 r.') === 'zarzadzenie-3-2025',
    'zarzadzenie_prezydenta_i_rektora must also produce the shared "zarzadzenie" page-slug prefix'
);
assertTrue(
    IdentifierSlugBuilder::build('uchwala', 'Nr. 12 z roku 2025') === 'uchwala-12-2025',
    'The "nr" marker may have a trailing period before the number'
);

// --- program_studiow is never sluggable this way (no numbered identifier convention) ---
assertTrue(
    IdentifierSlugBuilder::build('program_studiow', 'Program studiów nr 5 z 2025 roku') === null,
    'program_studiow must never produce an identifier-style slug, regardless of text content'
);

// --- unknown/empty type ---
assertTrue(
    IdentifierSlugBuilder::build('', 'uchwała nr 1 z 2025 roku') === null,
    'An unknown/empty type must not produce a slug'
);

// --- missing number ---
assertTrue(
    IdentifierSlugBuilder::build('uchwala', 'Uchwała Senatu z dnia 25 czerwca 2026 r. w sprawie czegoś') === null,
    'Must return null when no "nr X" pattern is found'
);

// --- missing year ---
assertTrue(
    IdentifierSlugBuilder::build('uchwala', 'Uchwała nr 31 Senatu w sprawie czegoś') === null,
    'Must return null when no 4-digit year (20xx) is found'
);

// --- buildNumberYear(): concatenates number+year without a type prefix or separators ---
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('uchwala', 'UCHWAŁA NR 31/2026 SENATU UCZELNI ŁAZARSKIEGO z dnia 25 czerwca 2026 r.') === '312026',
    'Must concatenate number and year with no separator, for use as a FAL subfolder name'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('zarzadzenie', 'Zarządzenie nr 7/2024 Rektora z dnia 3 stycznia 2024 r.') === 'rektor-72024',
    'The legacy zarzadzenie value must default to the "rektor-" authority prefix'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('zarzadzenie_rektora', 'Zarządzenie nr 7/2024 Rektora z dnia 3 stycznia 2024 r.') === 'rektor-72024',
    'zarzadzenie_rektora must produce a "rektor-" prefixed auto-folder name'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('zarzadzenie_prezydenta', 'Zarządzenie nr 9/2025 Prezydenta z dnia 1 lutego 2025 r.') === 'prezydent-92025',
    'zarzadzenie_prezydenta must produce a "prezydent-" prefixed auto-folder name'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('zarzadzenie_prezydenta_i_rektora', 'Zarządzenie nr 3/2025 Prezydenta i Rektora z dnia 1 marca 2025 r.') === 'prezydent-rektor-32025',
    'zarzadzenie_prezydenta_i_rektora must produce a "prezydent-rektor-" prefixed auto-folder name'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('uchwala', 'UCHWAŁA NR 31/2026 SENATU UCZELNI ŁAZARSKIEGO z dnia 25 czerwca 2026 r.') === '312026',
    'uchwala must never get an authority prefix (only one sub-type exists)'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('program_studiow', 'Program studiów nr 5 z 2025 roku') === null,
    'program_studiow must never produce an auto-folder name either'
);
assertTrue(
    IdentifierSlugBuilder::buildNumberYear('uchwala', 'Uchwała Senatu z dnia 25 czerwca 2026 r. w sprawie czegoś') === null,
    'Must return null when no "nr X" pattern is found'
);

echo sprintf("%d IdentifierSlugBuilder assertions passed.\n", $assertions);
