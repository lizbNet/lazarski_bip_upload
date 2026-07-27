<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;

/**
 * Guards DocumentSet status transitions so a set can't be re-confirmed, published twice,
 * or resurrected from a terminal state (cancelled/expired) by a retried request.
 *
 * Only DRAFT/STAGED/CANCELLED/EXPIRED are reachable from the Step 1 upload flow;
 * CONFIRMED/PUBLISHED are modelled here for later delivery steps but not yet triggered.
 */
final class DocumentSetLifecycle
{
    private const ALLOWED_TRANSITIONS = [
        0 => [1, 4, 5],   // DRAFT -> STAGED, CANCELLED, EXPIRED
        1 => [0, 2, 4, 5], // STAGED -> DRAFT (more files added), CONFIRMED, CANCELLED, EXPIRED
        2 => [3],         // CONFIRMED -> PUBLISHED
        3 => [],          // PUBLISHED is terminal
        4 => [],          // CANCELLED is terminal
        5 => [],          // EXPIRED is terminal
    ];

    public static function canTransition(DocumentSetStatus $from, DocumentSetStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true);
    }

    public static function assertCanTransition(DocumentSetStatus $from, DocumentSetStatus $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new \LogicException(
                sprintf('Cannot transition document set from "%s" to "%s".', $from->name, $to->name),
                1721550100
            );
        }
    }
}
