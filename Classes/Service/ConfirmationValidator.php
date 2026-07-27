<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;

/**
 * Pure "is this set ready to be confirmed" gate: required fields, staleness, slug collisions,
 * destination validity, and per-item conversion/title readiness. Deliberately has no DB/IO
 * dependency of its own - callers resolve those booleans (expiry, destination validity, slug
 * availability) via DestinationResolver/PageSlugChecker and pass the results in, so this stays
 * directly unit testable.
 *
 * Returns error CODES, not localized text - the caller is responsible for presentation
 * (including building richer per-item messages from the same item data it already has).
 */
final class ConfirmationValidator
{
    /**
     * @param array<int, array{status: DocumentItemStatus, title: string}> $items
     */
    public static function validate(
        DocumentSetStatus $status,
        bool $isExpired,
        string $approvedPageTitle,
        string $approvedSlug,
        int $approvedParentPageUid,
        bool $isDestinationAllowed,
        bool $isSlugAvailable,
        array $items
    ): ConfirmationValidationResult {
        $errors = [];

        if ($status !== DocumentSetStatus::STAGED) {
            $errors[] = 'set.notStaged';
        }
        if ($isExpired) {
            $errors[] = 'set.expired';
        }
        if (trim($approvedPageTitle) === '') {
            $errors[] = 'set.missingPageTitle';
        }
        if (trim($approvedSlug) === '') {
            $errors[] = 'set.missingSlug';
        } elseif (!$isSlugAvailable) {
            $errors[] = 'set.slugCollision';
        }
        if ($approvedParentPageUid <= 0 || !$isDestinationAllowed) {
            $errors[] = 'set.invalidDestination';
        }
        if ($items === []) {
            $errors[] = 'set.noItems';
        }

        foreach ($items as $item) {
            if ($item['status'] !== DocumentItemStatus::CONVERTED) {
                $errors[] = 'item.notConverted';
            }
            if (trim($item['title']) === '') {
                $errors[] = 'item.missingTitle';
            }
        }

        $uniqueErrors = array_values(array_unique($errors));

        return new ConfirmationValidationResult($uniqueErrors === [], $uniqueErrors);
    }
}
