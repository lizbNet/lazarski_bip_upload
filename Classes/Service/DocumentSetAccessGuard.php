<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * A staging set is only visible/actionable to the backend user who created it, unless
 * that user is an administrator. Prevents one editor from resuming or cancelling
 * another editor's in-progress upload by guessing/incrementing the set id.
 */
final class DocumentSetAccessGuard
{
    public static function isEditableBy(int $documentSetCruserId, int $currentBackendUserUid, bool $currentUserIsAdmin): bool
    {
        if ($currentUserIsAdmin) {
            return true;
        }

        return $currentBackendUserUid > 0 && $documentSetCruserId === $currentBackendUserUid;
    }
}
