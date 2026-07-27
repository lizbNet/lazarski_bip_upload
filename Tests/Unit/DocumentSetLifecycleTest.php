<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetLifecycle;

require_once dirname(__DIR__, 2) . '/Classes/Domain/Model/DocumentSetStatus.php';
require_once dirname(__DIR__, 2) . '/Classes/Service/DocumentSetLifecycle.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- reachable, allowed transitions from the Step 1 upload flow ---
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::DRAFT, DocumentSetStatus::STAGED),
    'DRAFT -> STAGED must be allowed (first successful upload)'
);
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::STAGED, DocumentSetStatus::DRAFT),
    'STAGED -> DRAFT must be allowed (adding more files re-enters staging)'
);
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::DRAFT, DocumentSetStatus::CANCELLED),
    'DRAFT -> CANCELLED must be allowed'
);
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::STAGED, DocumentSetStatus::CANCELLED),
    'STAGED -> CANCELLED must be allowed'
);
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::DRAFT, DocumentSetStatus::EXPIRED),
    'DRAFT -> EXPIRED must be allowed'
);
assertTrue(
    DocumentSetLifecycle::canTransition(DocumentSetStatus::STAGED, DocumentSetStatus::EXPIRED),
    'STAGED -> EXPIRED must be allowed'
);

// --- terminal states reject every transition, including re-entering themselves ---
foreach ([DocumentSetStatus::CANCELLED, DocumentSetStatus::EXPIRED, DocumentSetStatus::PUBLISHED] as $terminal) {
    foreach (DocumentSetStatus::cases() as $target) {
        assertTrue(
            !DocumentSetLifecycle::canTransition($terminal, $target),
            sprintf('%s must be terminal: %s -> %s must be rejected', $terminal->name, $terminal->name, $target->name)
        );
    }
}

// --- a retried confirm/publish cannot happen twice ---
assertTrue(
    !DocumentSetLifecycle::canTransition(DocumentSetStatus::CANCELLED, DocumentSetStatus::CONFIRMED),
    'A cancelled set must never be resurrected into CONFIRMED'
);
assertTrue(
    !DocumentSetLifecycle::canTransition(DocumentSetStatus::PUBLISHED, DocumentSetStatus::PUBLISHED),
    'A published set cannot be republished'
);

// --- same-state transitions are always rejected (no-op guard) ---
foreach (DocumentSetStatus::cases() as $status) {
    assertTrue(
        !DocumentSetLifecycle::canTransition($status, $status),
        sprintf('%s -> %s (identical) must be rejected', $status->name, $status->name)
    );
}

// --- invalid transitions throw from assertCanTransition() ---
try {
    DocumentSetLifecycle::assertCanTransition(DocumentSetStatus::CANCELLED, DocumentSetStatus::STAGED);
    throw new RuntimeException('Expected LogicException for CANCELLED -> STAGED was not thrown.');
} catch (LogicException $e) {
    $assertions++;
}

// --- valid transitions do not throw ---
DocumentSetLifecycle::assertCanTransition(DocumentSetStatus::DRAFT, DocumentSetStatus::STAGED);
$assertions++;

echo sprintf("%d DocumentSetLifecycle assertions passed.\n", $assertions);
