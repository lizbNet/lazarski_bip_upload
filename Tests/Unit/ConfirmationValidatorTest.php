<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;
use PrimeServices\LazarskiBipUpload\Service\ConfirmationValidator;

require_once dirname(__DIR__, 2) . '/Classes/Domain/Model/DocumentItemStatus.php';
require_once dirname(__DIR__, 2) . '/Classes/Domain/Model/DocumentSetStatus.php';
require_once dirname(__DIR__, 2) . '/Classes/Service/ConfirmationValidationResult.php';
require_once dirname(__DIR__, 2) . '/Classes/Service/ConfirmationValidator.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

function validItems(): array
{
    return [
        ['status' => DocumentItemStatus::CONVERTED, 'title' => 'Uchwała nr 1'],
        ['status' => DocumentItemStatus::CONVERTED, 'title' => 'Załącznik'],
    ];
}

// --- a fully valid set passes with no errors ---
$result = ConfirmationValidator::validate(
    DocumentSetStatus::STAGED,
    false,
    'Uchwała w sprawie czegoś',
    'uchwala-w-sprawie-czegos',
    7,
    true,
    true,
    validItems()
);
assertTrue($result->isValid, 'A fully valid set must pass validation');
assertTrue($result->errors === [], 'A valid set must have no errors');

// --- wrong status blocks confirmation ---
$result = ConfirmationValidator::validate(
    DocumentSetStatus::DRAFT,
    false,
    'Title',
    'slug',
    7,
    true,
    true,
    validItems()
);
assertTrue(!$result->isValid, 'A non-STAGED set must not be confirmable');
assertTrue(in_array('set.notStaged', $result->errors, true), 'Must report the notStaged error code');

// --- expired set blocks confirmation ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, true, 'Title', 'slug', 7, true, true, validItems());
assertTrue(!$result->isValid, 'An expired set must not be confirmable');
assertTrue(in_array('set.expired', $result->errors, true), 'Must report the expired error code');

// --- missing page title blocks confirmation ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, '  ', 'slug', 7, true, true, validItems());
assertTrue(!$result->isValid, 'An empty/whitespace-only page title must block confirmation');
assertTrue(in_array('set.missingPageTitle', $result->errors, true), 'Must report the missingPageTitle error code');

// --- missing slug blocks confirmation, and does not also report a spurious collision ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, 'Title', '', 7, true, true, validItems());
assertTrue(!$result->isValid, 'An empty slug must block confirmation');
assertTrue(in_array('set.missingSlug', $result->errors, true), 'Must report the missingSlug error code');
assertTrue(!in_array('set.slugCollision', $result->errors, true), 'An empty slug must not ALSO report a collision error');

// --- slug collision blocks confirmation ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, 'Title', 'taken-slug', 7, true, false, validItems());
assertTrue(!$result->isValid, 'A colliding slug must block confirmation');
assertTrue(in_array('set.slugCollision', $result->errors, true), 'Must report the slugCollision error code');

// --- invalid/disallowed destination blocks confirmation ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, 'Title', 'slug', 999, false, true, validItems());
assertTrue(!$result->isValid, 'A disallowed destination must block confirmation');
assertTrue(in_array('set.invalidDestination', $result->errors, true), 'Must report the invalidDestination error code');

// --- a parent uid of 0 is invalid even if isDestinationAllowed were somehow true ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, 'Title', 'slug', 0, true, true, validItems());
assertTrue(!$result->isValid, 'A zero parent page uid must always block confirmation');
assertTrue(in_array('set.invalidDestination', $result->errors, true), 'Must report the invalidDestination error code for a zero parent');

// --- no items blocks confirmation ---
$result = ConfirmationValidator::validate(DocumentSetStatus::STAGED, false, 'Title', 'slug', 7, true, true, []);
assertTrue(!$result->isValid, 'A set with zero items must not be confirmable');
assertTrue(in_array('set.noItems', $result->errors, true), 'Must report the noItems error code');

// --- an item that failed conversion blocks confirmation ---
$result = ConfirmationValidator::validate(
    DocumentSetStatus::STAGED,
    false,
    'Title',
    'slug',
    7,
    true,
    true,
    [['status' => DocumentItemStatus::FAILED, 'title' => 'Something'], ['status' => DocumentItemStatus::CONVERTED, 'title' => 'Other']]
);
assertTrue(!$result->isValid, 'Any non-CONVERTED item must block confirmation');
assertTrue(in_array('item.notConverted', $result->errors, true), 'Must report the notConverted error code');

// --- an item with an empty title blocks confirmation ---
$result = ConfirmationValidator::validate(
    DocumentSetStatus::STAGED,
    false,
    'Title',
    'slug',
    7,
    true,
    true,
    [['status' => DocumentItemStatus::CONVERTED, 'title' => '   ']]
);
assertTrue(!$result->isValid, 'An item with an empty/whitespace-only title must block confirmation');
assertTrue(in_array('item.missingTitle', $result->errors, true), 'Must report the missingTitle error code');

// --- multiple failing items report the error code only once (deduped) ---
$result = ConfirmationValidator::validate(
    DocumentSetStatus::STAGED,
    false,
    'Title',
    'slug',
    7,
    true,
    true,
    [
        ['status' => DocumentItemStatus::FAILED, 'title' => 'A'],
        ['status' => DocumentItemStatus::FAILED, 'title' => 'B'],
    ]
);
assertTrue(
    count(array_keys($result->errors, 'item.notConverted', true)) === 1,
    'Repeated identical error codes across multiple items must be deduplicated to one entry'
);

echo sprintf("%d ConfirmationValidator assertions passed.\n", $assertions);
