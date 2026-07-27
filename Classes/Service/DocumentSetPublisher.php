<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;

/**
 * Turns a CONFIRMED document set into exactly one hidden BIP page: imports every item's final
 * PDF into FAL (in upload order), creates the page with those files as ordered pages.media
 * attachments, and marks each item PUBLISHED with its resulting sys_file uid.
 *
 * Failure-safe by construction: a FAL import failure or a page-creation failure both roll back
 * every file imported so far (never a half-imported, page-less set of orphaned FAL files) and
 * surface as PublishException - the caller leaves the document set in CONFIRMED status so a
 * retry (calling publish() again) can pick up cleanly.
 *
 * Idempotent: if the set already has a confirmedPage (a previous call already succeeded, e.g.
 * this is a retried request after some other, later failure), publish() returns it immediately
 * without redoing any work.
 *
 * Persistence-agnostic by design: on success, each item's finalFile/status are updated
 * in-memory only - the caller is responsible for persisting them (matching this controller's
 * existing convention of services mutating domain objects and the controller handling
 * repository update()/persistAll() calls), since Extbase Repository instances need a full DI/
 * persistence stack that would make this class much harder to unit test otherwise.
 */
class DocumentSetPublisher
{
    private const PAGE_DOKTYPE_STANDARD = 1;

    public function __construct(
        private readonly FalImporterInterface $falImporter,
        private readonly PageCreatorInterface $pageCreator,
        private readonly TemporaryUploadService $temporaryUploadService,
    ) {
    }

    /**
     * @param DocumentItem[] $items must all be DocumentItemStatus::CONVERTED (the caller's
     *        ConfirmationValidator gate already guarantees this); on success, each item's
     *        finalFile/status are set in-memory - the caller must still persist them
     * @throws PublishException
     */
    public function publish(DocumentSet $documentSet, array $items): int
    {
        if ($documentSet->getConfirmedPage() > 0) {
            return $documentSet->getConfirmedPage();
        }

        $importedFileUids = [];
        $folderIdentifier = self::resolveFolderIdentifier($documentSet);

        try {
            foreach ($items as $item) {
                $absolutePath = $this->temporaryUploadService->getStagingRootPath() . '/' . $item->getConvertedPath();
                $fileUid = $this->falImporter->importFile($absolutePath, self::buildTargetFileName($documentSet, $item), $folderIdentifier);
                $importedFileUids[] = $fileUid;
                $this->falImporter->setTitle($fileUid, self::resolveTitle($item));
            }
        } catch (FalImportException $exception) {
            $this->rollback($importedFileUids);
            throw new PublishException('Nie udało się zaimportować plików do systemu plików: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $pageUid = $this->pageCreator->createHiddenPage(
                [
                    'pid' => $documentSet->getApprovedParentPage(),
                    'title' => $documentSet->getApprovedPageTitle(),
                    'subtitle' => $documentSet->getApprovedSubtitle(),
                    'slug' => $documentSet->getApprovedSlug(),
                    'hidden' => 1,
                    'doktype' => self::PAGE_DOKTYPE_STANDARD,
                ],
                $importedFileUids
            );
        } catch (PageCreationException $exception) {
            $this->rollback($importedFileUids);
            throw new PublishException('Nie udało się utworzyć strony: ' . $exception->getMessage(), 0, $exception);
        }

        foreach ($items as $index => $item) {
            $item->setFinalFile($importedFileUids[$index]);
            $item->setStatusEnum(DocumentItemStatus::PUBLISHED);
        }

        return $pageUid;
    }

    /**
     * @param int[] $fileUids
     */
    private function rollback(array $fileUids): void
    {
        foreach ($fileUids as $fileUid) {
            $this->falImporter->deleteFile($fileUid);
        }
    }

    /**
     * The approved FAL folder is always just the base ("where uchwały/zarządzenia normally
     * go"); the auto-generated number+year subfolder (e.g. "312026") is appended here, at
     * actual publish time, only when the editor left the checkbox on - keeping the base folder
     * field itself untouched by the toggle (see DocumentImportController::reviewAction()'s
     * finalFalFolderPreview for the same computation, used there only for display).
     */
    private static function resolveFolderIdentifier(DocumentSet $documentSet): string
    {
        $folderIdentifier = $documentSet->getApprovedFalFolder();
        if ($documentSet->isIncludeAutoFolder() && $documentSet->getSuggestedAutoFolder() !== '') {
            $folderIdentifier = rtrim($folderIdentifier, '/') . '/' . $documentSet->getSuggestedAutoFolder() . '/';
        }

        return $folderIdentifier;
    }

    private static function resolveTitle(DocumentItem $item): string
    {
        return $item->getApprovedTitle() !== '' ? $item->getApprovedTitle() : $item->getSuggestedTitle();
    }

    /**
     * Prepends the set's approved file prefix (optional - empty means no prefix) to every
     * item's filename, e.g. "uchwala-31-2026_31-2026-uchwala-finanse.pdf" - lets an editor
     * make files traceable to their set even if someone browses the flat FAL folder without
     * the page context. No separator is inserted here: the prefix's own trailing character
     * (an underscore by default - see DocumentImportController::reviewAction()) is part of the
     * approved value itself, so the editor has full control over it. Actual on-disk
     * uniqueness/collisions are still FAL's own job (DuplicationBehavior::RENAME in
     * TypoFalImporter), this only builds the requested name.
     */
    private static function buildTargetFileName(DocumentSet $documentSet, DocumentItem $item): string
    {
        $stem = pathinfo($item->getOriginalFilename(), PATHINFO_FILENAME);

        return $documentSet->getApprovedFilePrefix() . $stem . '.pdf';
    }
}
