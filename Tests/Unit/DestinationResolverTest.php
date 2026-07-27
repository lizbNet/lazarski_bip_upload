<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Service\DestinationResolver;

require_once dirname(__DIR__, 2) . '/Classes/Service/DestinationResolver.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- a configured per-type parent wins over the default ---
assertTrue(
    DestinationResolver::resolveSuggestedParent('uchwala', 10, 20, 30, 99, 7) === 10,
    'A configured per-type parent must be suggested when > 0'
);
assertTrue(
    DestinationResolver::resolveSuggestedParent('zarzadzenie', 10, 20, 30, 99, 7) === 20,
    'Each type must resolve to its own configured parent'
);
assertTrue(
    DestinationResolver::resolveSuggestedParent('program_studiow', 10, 20, 30, 99, 7) === 30,
    'program_studiow must resolve to its own configured parent'
);

// --- every zarzadzenie sub-type (split by issuing authority) shares the same configured parent ---
assertTrue(
    DestinationResolver::resolveSuggestedParent('zarzadzenie_rektora', 10, 20, 30, 99, 7) === 20,
    'zarzadzenie_rektora must resolve to the shared "zarzadzenie" configured parent'
);
assertTrue(
    DestinationResolver::resolveSuggestedParent('zarzadzenie_prezydenta', 10, 20, 30, 99, 7) === 20,
    'zarzadzenie_prezydenta must resolve to the shared "zarzadzenie" configured parent'
);
assertTrue(
    DestinationResolver::resolveSuggestedParent('zarzadzenie_prezydenta_i_rektora', 10, 20, 30, 99, 7) === 20,
    'zarzadzenie_prezydenta_i_rektora must resolve to the shared "zarzadzenie" configured parent'
);

// --- falls back to the configured default when the type has no mapping (0) ---
assertTrue(
    DestinationResolver::resolveSuggestedParent('uchwala', 0, 20, 30, 99, 7) === 99,
    'A zero per-type mapping must fall back to the configured default parent'
);

// --- unknown/empty type falls back to the default ---
assertTrue(
    DestinationResolver::resolveSuggestedParent('', 10, 20, 30, 99, 7) === 99,
    'An empty/unknown type must fall back to the configured default parent'
);
assertTrue(
    DestinationResolver::resolveSuggestedParent('something_unrecognized', 10, 20, 30, 99, 7) === 99,
    'An unrecognized type string must fall back to the configured default parent'
);

// --- falls back all the way to the BIP root when neither a per-type nor a default parent is configured ---
assertTrue(
    DestinationResolver::resolveSuggestedParent('uchwala', 0, 0, 0, 0, 7) === 7,
    'With no per-type and no default parent configured, the BIP root itself must be suggested'
);

// --- resolveSuggestedFalFolder(): a configured per-type folder wins over the default ---
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('uchwala', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/uchwaly/',
    'A configured per-type FAL folder must be suggested when non-empty'
);
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('zarzadzenie', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/zarzadzenia/',
    'Each type must resolve to its own configured FAL folder'
);
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('program_studiow', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/programy/',
    'program_studiow must resolve to its own configured FAL folder'
);

// --- every zarzadzenie sub-type shares the same configured FAL folder ---
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('zarzadzenie_rektora', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/zarzadzenia/',
    'zarzadzenie_rektora must resolve to the shared "zarzadzenie" configured FAL folder'
);
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('zarzadzenie_prezydenta', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/zarzadzenia/',
    'zarzadzenie_prezydenta must resolve to the shared "zarzadzenie" configured FAL folder'
);
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('zarzadzenie_prezydenta_i_rektora', '/bip-dokumenty/uchwaly/', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/zarzadzenia/',
    'zarzadzenie_prezydenta_i_rektora must resolve to the shared "zarzadzenie" configured FAL folder'
);

// --- resolveSuggestedFalFolder(): an empty per-type mapping falls back to the default ---
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('uchwala', '', '/bip-dokumenty/zarzadzenia/', '/bip-dokumenty/programy/', '/bip-dokumenty/') === '/bip-dokumenty/',
    'An empty per-type FAL folder must fall back to the configured default folder'
);

// --- resolveSuggestedFalFolder(): unknown/empty type falls back to the default ---
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('', '/a/', '/b/', '/c/', '/bip-dokumenty/') === '/bip-dokumenty/',
    'An empty/unknown type must fall back to the configured default FAL folder'
);
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('something_unrecognized', '/a/', '/b/', '/c/', '/bip-dokumenty/') === '/bip-dokumenty/',
    'An unrecognized type string must fall back to the configured default FAL folder'
);

// --- resolveSuggestedFalFolder(): whitespace-only per-type values are treated as unconfigured ---
assertTrue(
    DestinationResolver::resolveSuggestedFalFolder('uchwala', '   ', '/b/', '/c/', '/bip-dokumenty/') === '/bip-dokumenty/',
    'A whitespace-only per-type folder must be treated as unconfigured and fall back to the default'
);

echo sprintf("%d DestinationResolver assertions passed.\n", $assertions);
