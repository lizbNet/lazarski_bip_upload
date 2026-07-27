<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Service\DocumentSetAccessGuard;

require_once dirname(__DIR__, 2) . '/Classes/Service/DocumentSetAccessGuard.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- the creator can edit their own set ---
assertTrue(
    DocumentSetAccessGuard::isEditableBy(5, 5, false),
    'The backend user who created the set must be able to edit it'
);

// --- a different, non-admin editor is denied ---
assertTrue(
    !DocumentSetAccessGuard::isEditableBy(5, 6, false),
    'A different non-admin backend user must not be able to edit someone else\'s set'
);

// --- an admin can edit any set, regardless of ownership ---
assertTrue(
    DocumentSetAccessGuard::isEditableBy(5, 6, true),
    'An admin backend user must be able to edit any document set'
);

// --- a logged-out/anonymous user id (0) never matches, even against an unowned (0) set ---
assertTrue(
    !DocumentSetAccessGuard::isEditableBy(0, 0, false),
    'An unauthenticated/zero backend user id must never be treated as the owner'
);

// --- an admin with a zero user id (defensive edge case) is still granted access ---
assertTrue(
    DocumentSetAccessGuard::isEditableBy(5, 0, true),
    'Admin flag alone must be sufficient regardless of the current user id value'
);

echo sprintf("%d DocumentSetAccessGuard assertions passed.\n", $assertions);
