<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * One suggested value for a field (page title, subtitle, slug, per-file title), with enough
 * provenance for a review screen to explain "why" alongside the top pick.
 */
final class Candidate
{
    public function __construct(
        public readonly string $value,
        public readonly string $source,
        public readonly int $confidence,
        public readonly string $reason,
    ) {
    }
}
