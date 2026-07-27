<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Merges candidates from multiple sources into one ranked list: deduplicates by
 * case/whitespace-folded value (keeping the highest-confidence duplicate), then sorts
 * by confidence descending.
 */
final class CandidateRanker
{
    /**
     * @param Candidate[] $candidates
     * @return Candidate[]
     */
    public static function rank(array $candidates): array
    {
        $bestByKey = [];
        foreach ($candidates as $candidate) {
            $key = self::foldKey($candidate->value);
            if ($key === '') {
                continue;
            }
            if (!isset($bestByKey[$key]) || $candidate->confidence > $bestByKey[$key]->confidence) {
                $bestByKey[$key] = $candidate;
            }
        }

        $ranked = array_values($bestByKey);
        usort($ranked, static fn (Candidate $a, Candidate $b): int => $b->confidence <=> $a->confidence);

        return $ranked;
    }

    private static function foldKey(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
