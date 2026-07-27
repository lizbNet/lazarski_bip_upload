<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * A suggested document-set type. `type` is '' (unknown) when no signal was found -
 * the classifier never forces a guess.
 */
final class TypeSuggestion
{
    public function __construct(
        public readonly string $type,
        public readonly int $confidence,
        public readonly string $reason,
    ) {
    }
}
