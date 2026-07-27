<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Conversion\ConversionException;
use PrimeServices\LazarskiBipUpload\Conversion\DocumentConverterInterface;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;
use PrimeServices\LazarskiBipUpload\Domain\Repository\DocumentItemRepository;
use PrimeServices\LazarskiBipUpload\Service\DocumentConversionOrchestrator;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;

require_once dirname(__DIR__, 5) . '/vendor/autoload.php';

/**
 * Records update() calls instead of touching real persistence, so the orchestrator can be
 * exercised without a full Extbase/DB bootstrap.
 */
final class FakeDocumentItemRepository extends DocumentItemRepository
{
    /** @var DocumentItem[] */
    public array $updated = [];

    public function update($modifiedObject)
    {
        $this->updated[] = $modifiedObject;
    }
}

final class FakeDocumentConverter implements DocumentConverterInterface
{
    /** @var array[] */
    public array $calls = [];

    public function __construct(private \Closure $behavior)
    {
    }

    public function convertToPdf(string $sourceAbsolutePath, string $outputDirectoryAbsolutePath): string
    {
        $this->calls[] = [$sourceAbsolutePath, $outputDirectoryAbsolutePath];

        return ($this->behavior)($sourceAbsolutePath, $outputDirectoryAbsolutePath);
    }
}

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

$scratchRoot = sys_get_temp_dir() . '/lbu_orchestrator_test_' . bin2hex(random_bytes(6));
$uploadService = new TemporaryUploadService($scratchRoot);

$documentSet = new DocumentSet();
$documentSet->setStagingToken(str_repeat('a', 32));

function makeStoredItem(TemporaryUploadService $uploadService, string $setToken, string $extension, DocumentItemStatus $status): DocumentItem
{
    $directory = $uploadService->getStagingRootPath() . '/' . $setToken;
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $basename = bin2hex(random_bytes(4)) . '.' . $extension;
    file_put_contents($directory . '/' . $basename, 'content');

    $item = new DocumentItem();
    $item->setFileExtension($extension);
    $item->setStoredPath($setToken . '/' . $basename);
    $item->setStatusEnum($status);

    return $item;
}

// --- PDF pass-through: converted without calling the converter ---
$pdfItem = makeStoredItem($uploadService, $documentSet->getStagingToken(), 'pdf', DocumentItemStatus::UPLOADED);
$converter = new FakeDocumentConverter(fn () => throw new RuntimeException('Converter must not be called for PDF items.'));
$repository = new FakeDocumentItemRepository();
$orchestrator = new DocumentConversionOrchestrator($converter, $uploadService, $repository);

$orchestrator->convertPendingItems($documentSet, [$pdfItem]);

assertTrue($pdfItem->getStatusEnum() === DocumentItemStatus::CONVERTED, 'A PDF item must end up CONVERTED');
assertTrue($pdfItem->getConvertedPath() === $pdfItem->getStoredPath(), 'A PDF item\'s converted_path must equal its stored_path (no-op conversion)');
assertTrue(count($converter->calls) === 0, 'The converter must never be invoked for a PDF item');
assertTrue(count($repository->updated) === 1, 'update() must be called exactly once for the processed item');

// --- DOCX success ---
$docxItem = makeStoredItem($uploadService, $documentSet->getStagingToken(), 'docx', DocumentItemStatus::UPLOADED);
$converter = new FakeDocumentConverter(function (string $source, string $outputDir): string {
    $target = $outputDir . '/' . pathinfo($source, PATHINFO_FILENAME) . '.pdf';
    file_put_contents($target, '%PDF-1.4');
    return $target;
});
$repository = new FakeDocumentItemRepository();
$orchestrator = new DocumentConversionOrchestrator($converter, $uploadService, $repository);

$orchestrator->convertPendingItems($documentSet, [$docxItem]);

assertTrue($docxItem->getStatusEnum() === DocumentItemStatus::CONVERTED, 'A successfully converted DOCX item must end up CONVERTED');
assertTrue(
    $docxItem->getConvertedPath() === $documentSet->getStagingToken() . '/' . pathinfo($docxItem->getStoredPath(), PATHINFO_FILENAME) . '.pdf',
    'converted_path must be the set-token-relative path to the produced PDF'
);
assertTrue(count($converter->calls) === 1, 'The converter must be invoked exactly once for a DOCX item');

// --- DOCX conversion failure: item is FAILED, error is recorded, batch is not aborted ---
$docxItemA = makeStoredItem($uploadService, $documentSet->getStagingToken(), 'docx', DocumentItemStatus::UPLOADED);
$docxItemB = makeStoredItem($uploadService, $documentSet->getStagingToken(), 'docx', DocumentItemStatus::UPLOADED);
$callCount = 0;
$converter = new FakeDocumentConverter(function () use (&$callCount): string {
    $callCount++;
    if ($callCount === 1) {
        throw new ConversionException('Conversion process exited with status 1.');
    }
    throw new ConversionException('Conversion process exited with status 1.'); // both fail, batch must still finish
});
$repository = new FakeDocumentItemRepository();
$orchestrator = new DocumentConversionOrchestrator($converter, $uploadService, $repository);

$orchestrator->convertPendingItems($documentSet, [$docxItemA, $docxItemB]);

assertTrue($docxItemA->getStatusEnum() === DocumentItemStatus::FAILED, 'First failed item must end up FAILED');
assertTrue($docxItemA->getErrorMessage() === 'Conversion process exited with status 1.', 'The conversion exception message must be recorded as the error message');
assertTrue($docxItemB->getStatusEnum() === DocumentItemStatus::FAILED, 'Second item must also be processed (batch is not aborted by the first failure)');
assertTrue(count($repository->updated) === 2, 'Both items must be persisted despite the failure');

// --- items not in UPLOADED status are skipped entirely ---
$alreadyConverted = makeStoredItem($uploadService, $documentSet->getStagingToken(), 'docx', DocumentItemStatus::CONVERTED);
$converter = new FakeDocumentConverter(fn () => throw new RuntimeException('Converter must not be called for a non-UPLOADED item.'));
$repository = new FakeDocumentItemRepository();
$orchestrator = new DocumentConversionOrchestrator($converter, $uploadService, $repository);

$orchestrator->convertPendingItems($documentSet, [$alreadyConverted]);

assertTrue(count($converter->calls) === 0, 'The converter must not be invoked for an already-processed item');
assertTrue(count($repository->updated) === 0, 'update() must not be called for a skipped item');

exec('rm -rf ' . escapeshellarg($scratchRoot));

echo sprintf("%d DocumentConversionOrchestrator assertions passed.\n", $assertions);
