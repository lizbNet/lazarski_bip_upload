<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;

$vendorAutoload = null;
for ($dir = __DIR__; $dir !== ($parent = dirname($dir)); $dir = $parent) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $vendorAutoload = $dir . '/vendor/autoload.php';
        break;
    }
}
require_once $vendorAutoload;
// Load the models from this checkout, not from an installed copy the root autoloader may point to.
require_once dirname(__DIR__, 2) . '/Classes/Domain/Model/DocumentSet.php';
require_once dirname(__DIR__, 2) . '/Classes/Domain/Model/DocumentItem.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- new records are stored on pid 0, never on the page selected in the page tree ---
assertTrue((new DocumentSet())->getPid() === 0, 'a new DocumentSet must default to pid 0');
assertTrue((new DocumentItem())->getPid() === 0, 'a new DocumentItem must default to pid 0');

// --- records never block a page's doktype check ---
foreach (['documentset', 'documentitem'] as $table) {
    $tca = require dirname(__DIR__, 2) . '/Configuration/TCA/tx_lazarskibipupload_domain_model_' . $table . '.php';
    assertTrue(
        ($tca['ctrl']['security']['ignorePageTypeRestriction'] ?? false) === true,
        $table . ' TCA must set ctrl.security.ignorePageTypeRestriction'
    );
    assertTrue(($tca['ctrl']['rootLevel'] ?? null) === -1, $table . ' TCA must allow pid 0 (rootLevel -1)');
}

echo sprintf("%d RecordStoragePid assertions passed.\n", $assertions);
