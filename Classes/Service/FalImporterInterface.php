<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * Seam between DocumentSetPublisher and the actual FAL API, so publication logic (ordering,
 * rollback-on-failure, idempotency) can be tested without a bootstrapped TYPO3 storage/DB.
 */
interface FalImporterInterface
{
    /**
     * Imports a local file into the configured FAL storage, under $folderIdentifier (the
     * set's effective FAL folder - created automatically if missing), conflict-safe (renamed
     * on a name collision, never silently overwritten).
     *
     * @throws FalImportException
     */
    public function importFile(string $absolutePath, string $targetFileName, string $folderIdentifier): int;

    /**
     * @throws FalImportException
     */
    public function setTitle(int $fileUid, string $title): void;

    /**
     * Best-effort rollback of a file imported by importFile() - never throws, since it is
     * only ever called while already handling a prior failure.
     */
    public function deleteFile(int $fileUid): void;
}
