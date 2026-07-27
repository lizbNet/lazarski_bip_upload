<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\Candidate;
use PrimeServices\LazarskiBipUpload\Analysis\CandidateRanker;

require_once dirname(__DIR__, 3) . '/Classes/Analysis/Candidate.php';
require_once dirname(__DIR__, 3) . '/Classes/Analysis/CandidateRanker.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- sorts by confidence descending ---
$ranked = CandidateRanker::rank([
    new Candidate('Filename Title', 'filename', 40, 'from filename'),
    new Candidate('DOCX Property Title', 'docx_property', 90, 'from docx property'),
    new Candidate('Heading Title', 'docx_heading', 75, 'from heading'),
]);
assertTrue(count($ranked) === 3, 'All three distinct candidates must survive');
assertTrue($ranked[0]->value === 'DOCX Property Title', 'Highest confidence candidate must be first');
assertTrue($ranked[1]->value === 'Heading Title', 'Second highest confidence must be second');
assertTrue($ranked[2]->value === 'Filename Title', 'Lowest confidence must be last');

// --- dedupes by case/whitespace-folded value, keeping the highest-confidence duplicate ---
$ranked = CandidateRanker::rank([
    new Candidate('Uchwała Senatu', 'filename', 40, 'from filename'),
    new Candidate('  uchwała senatu  ', 'docx_property', 90, 'from docx property'),
]);
assertTrue(count($ranked) === 1, 'Case/whitespace-equivalent values must be deduplicated to one');
assertTrue($ranked[0]->confidence === 90, 'The higher-confidence duplicate must be the one that survives');

// --- empty-value candidates are dropped ---
$ranked = CandidateRanker::rank([
    new Candidate('', 'filename', 40, 'empty'),
    new Candidate('   ', 'docx_property', 90, 'whitespace only'),
    new Candidate('Real Title', 'pdf_title', 65, 'real'),
]);
assertTrue(count($ranked) === 1, 'Empty and whitespace-only candidates must be dropped');
assertTrue($ranked[0]->value === 'Real Title', 'Only the non-empty candidate must remain');

// --- empty input list ---
assertTrue(CandidateRanker::rank([]) === [], 'An empty candidate list must rank to an empty list');

echo sprintf("%d CandidateRanker assertions passed.\n", $assertions);
