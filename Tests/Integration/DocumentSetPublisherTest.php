<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetPublisher;
use PrimeServices\LazarskiBipUpload\Service\FalImporterInterface;
use PrimeServices\LazarskiBipUpload\Service\FalImportException;
use PrimeServices\LazarskiBipUpload\Service\PageCreationException;
use PrimeServices\LazarskiBipUpload\Service\PageCreatorInterface;
use PrimeServices\LazarskiBipUpload\Service\PublishException;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;

$vendorAutoload = null;
for ($dir = __DIR__; $dir !== ($parent = dirname($dir)); $dir = $parent) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $vendorAutoload = $dir . '/vendor/autoload.php';
        break;
    }
}
require_once $vendorAutoload;

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

final class FakeFalImporter implements FalImporterInterface
{
    /** @var array{path: string, name: string, folder: string}[] */
    public array $importCalls = [];
    /** @var array{uid: int, title: string}[] */
    public array $titleCalls = [];
    /** @var int[] */
    public array $deleteCalls = [];

    private int $nextUid = 100;

    public function __construct(private readonly ?\Closure $importBehavior = null)
    {
    }

    public function importFile(string $absolutePath, string $targetFileName, string $folderIdentifier): int
    {
        $this->importCalls[] = ['path' => $absolutePath, 'name' => $targetFileName, 'folder' => $folderIdentifier];

        if ($this->importBehavior !== null) {
            return ($this->importBehavior)($absolutePath, $targetFileName, $this->nextUid);
        }

        return $this->nextUid++;
    }

    public function setTitle(int $fileUid, string $title): void
    {
        $this->titleCalls[] = ['uid' => $fileUid, 'title' => $title];
    }

    public function deleteFile(int $fileUid): void
    {
        $this->deleteCalls[] = $fileUid;
    }
}

final class FakePageCreator implements PageCreatorInterface
{
    public ?array $lastPageFields = null;
    public ?array $lastOrderedFileUids = null;

    public function __construct(private readonly \Closure $behavior)
    {
    }

    public function createHiddenPage(array $pageFields, array $orderedFileUids): int
    {
        $this->lastPageFields = $pageFields;
        $this->lastOrderedFileUids = $orderedFileUids;

        return ($this->behavior)($pageFields, $orderedFileUids);
    }
}

function makeStagingDirWithFiles(array $filenames): string
{
    $stagingRoot = sys_get_temp_dir() . '/lbu_publisher_test_' . bin2hex(random_bytes(6));
    $setToken = str_repeat('b', 32);
    mkdir($stagingRoot . '/' . $setToken, 0775, true);
    foreach ($filenames as $filename) {
        file_put_contents($stagingRoot . '/' . $setToken . '/' . $filename, '%PDF-1.4 fake');
    }

    return $stagingRoot;
}

function removeDir(string $dir): void
{
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function makeDocumentSet(int $confirmedPage = 0): DocumentSet
{
    $documentSet = new DocumentSet();
    $documentSet->setApprovedParentPage(7);
    $documentSet->setApprovedPageTitle('Uchwala testowa');
    $documentSet->setApprovedSubtitle('Podtytul');
    $documentSet->setApprovedSlug('uchwala-test');
    $documentSet->setApprovedFalFolder('/bip-dokumenty/uchwaly/');
    $documentSet->setApprovedAuthor('Uczelnia Lazarskiego');
    $documentSet->setConfirmedPage($confirmedPage);

    return $documentSet;
}

// --- happy path: both items imported in order, page created, items updated in-memory ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf', 'file1.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('31-2026-uchwala.docx');
$item0->setFileExtension('docx');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('Uchwala nr 31/2026');
$item1 = new DocumentItem();
$item1->setOriginalFilename('zalacznik.pdf');
$item1->setFileExtension('pdf');
$item1->setConvertedPath(str_repeat('b', 32) . '/file1.pdf');
$item1->setStatusEnum(DocumentItemStatus::CONVERTED);
$item1->setApprovedTitle('');
$item1->setSuggestedTitle('Zalacznik do uchwaly');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 55);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));

$documentSet = makeDocumentSet();
$publishStartedAt = time();
$pageUid = $publisher->publish($documentSet, [$item0, $item1]);

assertTrue($pageUid === 55, 'Must return the page uid produced by the page creator');
assertTrue(count($falImporter->importCalls) === 2, 'Must import exactly one file per item');
assertTrue($falImporter->importCalls[0]['name'] === '31-2026-uchwala.pdf', 'Target filename must be the original filename stem with a .pdf extension');
assertTrue($falImporter->importCalls[1]['name'] === 'zalacznik.pdf', 'Target filename for an already-PDF item must also be original-stem + .pdf');
assertTrue($falImporter->importCalls[0]['folder'] === '/bip-dokumenty/uchwaly/', 'The document set\'s approved FAL folder must be passed through to every import call');
assertTrue($falImporter->importCalls[1]['folder'] === '/bip-dokumenty/uchwaly/', 'The document set\'s approved FAL folder must be passed through to every import call');
assertTrue($falImporter->titleCalls[0]['title'] === 'Uchwala nr 31/2026', 'The approved title must be used when present');
assertTrue($falImporter->titleCalls[1]['title'] === 'Zalacznik do uchwaly', 'The suggested title must be used as a fallback when approved is empty');
assertTrue($pageCreator->lastOrderedFileUids === [100, 101], 'File uids must be passed to the page creator in item order');
assertTrue($pageCreator->lastPageFields['pid'] === 7, 'Page pid must be the approved parent page');
assertTrue($pageCreator->lastPageFields['title'] === 'Uchwala testowa', 'Page title must be the approved page title');
assertTrue($pageCreator->lastPageFields['hidden'] === 1, 'The created page must always be hidden');
assertTrue($pageCreator->lastPageFields['doktype'] === 1, 'The created page must use the standard doktype');
assertTrue($pageCreator->lastPageFields['author'] === 'Uczelnia Lazarskiego', 'The approved author must be written to the page, since the BIP metryczka renders it');
assertTrue(($pageCreator->lastPageFields['starttime'] ?? 0) > 0, 'starttime must be set explicitly - the metryczka reads it before falling back to crdate');
assertTrue(($pageCreator->lastPageFields['starttime'] ?? 0) >= $publishStartedAt, 'With no approved issue date, starttime must fall back to the publication time');
assertTrue(!array_key_exists('lastUpdated', $pageCreator->lastPageFields), 'lastUpdated must be left unset so the metryczka keeps falling back to the auto-updated tstamp');
assertTrue($item0->getFinalFile() === 100, 'First item must receive its imported file uid');
assertTrue($item1->getFinalFile() === 101, 'Second item must receive its imported file uid');
assertTrue($item0->getStatusEnum() === DocumentItemStatus::PUBLISHED, 'Items must be marked PUBLISHED on success');
assertTrue($item1->getStatusEnum() === DocumentItemStatus::PUBLISHED, 'Items must be marked PUBLISHED on success');
removeDir($stagingRoot);

// --- idempotency guard: an already-confirmedPage set must short-circuit without doing any work ---
$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => throw new RuntimeException('Must not be called when already published.'));
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService(sys_get_temp_dir()));
$documentSet = makeDocumentSet(42);

$pageUid = $publisher->publish($documentSet, []);
assertTrue($pageUid === 42, 'Must return the existing confirmedPage immediately');
assertTrue(count($falImporter->importCalls) === 0, 'The FAL importer must not be called when already published');

// --- FAL import failure mid-way: rollback only the files imported so far, page creator never called ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf', 'file1.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('a.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');
$item1 = new DocumentItem();
$item1->setOriginalFilename('b.pdf');
$item1->setFileExtension('pdf');
$item1->setConvertedPath(str_repeat('b', 32) . '/file1.pdf');
$item1->setStatusEnum(DocumentItemStatus::CONVERTED);
$item1->setApprovedTitle('B');

$falImporter = new FakeFalImporter(function (string $path, string $name, int $nextUid) {
    if ($name === 'b.pdf') {
        throw new FalImportException('disk full');
    }
    return $nextUid;
});
$pageCreator = new FakePageCreator(fn () => throw new RuntimeException('Must not be called when import failed.'));
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();

try {
    $publisher->publish($documentSet, [$item0, $item1]);
    throw new RuntimeException('Expected PublishException for a mid-way FAL import failure.');
} catch (PublishException $e) {
    assertTrue(str_contains($e->getMessage(), 'zaimportować'), 'Message must indicate an import failure');
}
assertTrue($falImporter->deleteCalls === [100], 'Only the successfully-imported file (the first) must be rolled back');
assertTrue($documentSet->getConfirmedPage() === 0, 'confirmedPage must remain unset after a failed publish');
removeDir($stagingRoot);

// --- page creation failure: rollback ALL imported files ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf', 'file1.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('a.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');
$item1 = new DocumentItem();
$item1->setOriginalFilename('b.pdf');
$item1->setFileExtension('pdf');
$item1->setConvertedPath(str_repeat('b', 32) . '/file1.pdf');
$item1->setStatusEnum(DocumentItemStatus::CONVERTED);
$item1->setApprovedTitle('B');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => throw new PageCreationException('DataHandler exploded'));
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();

try {
    $publisher->publish($documentSet, [$item0, $item1]);
    throw new RuntimeException('Expected PublishException for a page-creation failure.');
} catch (PublishException $e) {
    assertTrue(str_contains($e->getMessage(), 'utworzyć strony'), 'Message must indicate a page-creation failure');
}
assertTrue($falImporter->deleteCalls === [100, 101], 'Both successfully-imported files must be rolled back when page creation fails');
assertTrue($item0->getStatusEnum() === DocumentItemStatus::CONVERTED, 'Items must remain CONVERTED (not PUBLISHED) after a failed publish');
removeDir($stagingRoot);

// --- auto-folder checkbox ON + a suggested number+year: appended as a subfolder at publish time ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('a.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 60);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();
$documentSet->setSuggestedAutoFolder('312026');
$documentSet->setIncludeAutoFolder(true);

$publisher->publish($documentSet, [$item0]);
assertTrue($falImporter->importCalls[0]['folder'] === '/bip-dokumenty/uchwaly/312026/', 'The auto-generated number+year subfolder must be appended when the checkbox is on');
removeDir($stagingRoot);

// --- auto-folder checkbox OFF: base folder used as-is, even with a suggestion available ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('a.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 61);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();
$documentSet->setSuggestedAutoFolder('312026');
$documentSet->setIncludeAutoFolder(false);

$publisher->publish($documentSet, [$item0]);
assertTrue($falImporter->importCalls[0]['folder'] === '/bip-dokumenty/uchwaly/', 'Unchecking the box must leave the base folder untouched even when a subfolder is available');
removeDir($stagingRoot);

// --- default DocumentSet state: includeAutoFolder defaults to true, but no effect without a suggestion ---
assertTrue((new DocumentSet())->isIncludeAutoFolder() === true, 'includeAutoFolder must default to true (checkbox selected by default)');

// --- approved file prefix, when set, is prepended to every item's target filename ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf', 'file1.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('31-2026-uchwala-finanse.docx');
$item0->setFileExtension('docx');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');
$item1 = new DocumentItem();
$item1->setOriginalFilename('zalacznik.pdf');
$item1->setFileExtension('pdf');
$item1->setConvertedPath(str_repeat('b', 32) . '/file1.pdf');
$item1->setStatusEnum(DocumentItemStatus::CONVERTED);
$item1->setApprovedTitle('B');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 62);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();
$documentSet->setApprovedFilePrefix('uchwala-31-2026_');

$publisher->publish($documentSet, [$item0, $item1]);
assertTrue($falImporter->importCalls[0]['name'] === 'uchwala-31-2026_31-2026-uchwala-finanse.pdf', 'The approved prefix must be prepended verbatim (no extra separator inserted) to the first item\'s filename');
assertTrue($falImporter->importCalls[1]['name'] === 'uchwala-31-2026_zalacznik.pdf', 'The approved prefix must be prepended to every item\'s filename, not just the first');
removeDir($stagingRoot);

// --- an empty (unset) file prefix leaves filenames untouched - it is genuinely optional ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('zalacznik.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 63);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();

$publisher->publish($documentSet, [$item0]);
assertTrue($falImporter->importCalls[0]['name'] === 'zalacznik.pdf', 'No prefix configured must leave the filename exactly as before (no separator, no empty-prefix artifact)');
removeDir($stagingRoot);

// --- an approved issue date overrides the publication time as the page's starttime ---
$stagingRoot = makeStagingDirWithFiles(['file0.pdf']);
$item0 = new DocumentItem();
$item0->setOriginalFilename('zalacznik.pdf');
$item0->setFileExtension('pdf');
$item0->setConvertedPath(str_repeat('b', 32) . '/file0.pdf');
$item0->setStatusEnum(DocumentItemStatus::CONVERTED);
$item0->setApprovedTitle('A');

$falImporter = new FakeFalImporter();
$pageCreator = new FakePageCreator(fn () => 64);
$publisher = new DocumentSetPublisher($falImporter, $pageCreator, new TemporaryUploadService($stagingRoot));
$documentSet = makeDocumentSet();
// 2026-03-12 00:00:00 UTC - a backlog import of an older document.
$issuedAt = 1773273600;
$documentSet->setApprovedStartDate($issuedAt);

$publisher->publish($documentSet, [$item0]);
assertTrue($pageCreator->lastPageFields['starttime'] === $issuedAt, 'An approved issue date must be used verbatim as starttime, not the publication time');
removeDir($stagingRoot);

echo sprintf("%d DocumentSetPublisher assertions passed.\n", $assertions);
