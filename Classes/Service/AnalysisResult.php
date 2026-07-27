<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use PrimeServices\LazarskiBipUpload\Analysis\Candidate;
use PrimeServices\LazarskiBipUpload\Analysis\TypeSuggestion;

final class AnalysisResult
{
    /**
     * @param Candidate[] $pageTitleCandidates ranked, highest confidence first
     * @param Candidate[] $subtitleCandidates ranked, highest confidence first
     * @param Candidate[] $slugCandidates ranked, highest confidence first
     * @param array<int, Candidate[]> $itemTitleCandidates keyed by DocumentItem uid, each ranked
     */
    public function __construct(
        public readonly TypeSuggestion $type,
        public readonly array $pageTitleCandidates,
        public readonly array $subtitleCandidates,
        public readonly array $slugCandidates,
        public readonly array $itemTitleCandidates,
        public readonly string $suggestedAutoFolder = '',
    ) {
    }
}
