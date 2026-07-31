<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Imports a confirmed PDF into the admin-configured FAL storage (folder given per call - see
 * DestinationResolver::suggestFalFolderIdentifier() / DocumentSet::getApprovedFalFolder() for
 * how the effective per-set folder is resolved) and writes its sys_file_metadata.title, using
 * TYPO3's own FAL API (never a direct filesystem copy into public/ + database insert) so
 * indexing, driver capabilities, and conflict handling all stay correct.
 */
class TypoFalImporter implements FalImporterInterface
{
    private const EXTENSION_KEY = 'lazarski_bip_upload';
    private const DEFAULT_FOLDER_IDENTIFIER = '/bip-dokumenty/';

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ResourceFactory $resourceFactory,
        private readonly MetaDataRepository $metaDataRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function importFile(string $absolutePath, string $targetFileName, string $folderIdentifier): int
    {
        try {
            $storage = $this->resolveStorage();
            $folder = $this->resolveFolder($storage, $folderIdentifier);

            $file = $storage->addFile($absolutePath, $folder, $targetFileName, DuplicationBehavior::RENAME, true);

            return (int)$file->getUid();
        } catch (\Throwable $exception) {
            throw new FalImportException(
                sprintf('Could not import "%s" into FAL: %s', $targetFileName, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    public function setTitle(int $fileUid, string $title): void
    {
        try {
            $this->metaDataRepository->update($fileUid, ['title' => $title]);
        } catch (\Throwable $exception) {
            throw new FalImportException(
                sprintf('Could not write file metadata title for file uid %d: %s', $fileUid, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    public function deleteFile(int $fileUid): void
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $file->delete();
        } catch (FileDoesNotExistException|\Throwable $exception) {
            // Best-effort rollback only: this is already running while handling a prior
            // failure, so a rollback failure must never mask or replace the original error.
        }
    }

    private function resolveStorage(): ResourceStorage
    {
        $storageUid = $this->getIntSetting('falStorageUid', 1);
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null) {
            throw new FalImportException(sprintf('Configured FAL storage uid %d does not exist.', $storageUid));
        }

        return $storage;
    }

    private function resolveFolder(ResourceStorage $storage, string $folderIdentifier): Folder
    {
        $normalized = trim($folderIdentifier, '/');
        $identifier = '/' . ($normalized !== '' ? $normalized : trim(self::DEFAULT_FOLDER_IDENTIFIER, '/')) . '/';

        if ($storage->hasFolder($identifier)) {
            return $storage->getFolder($identifier);
        }

        $segments = array_values(array_filter(explode('/', trim($identifier, '/')), static fn (string $segment): bool => $segment !== ''));
        $current = $storage->getRootLevelFolder();
        foreach ($segments as $segment) {
            $current = $storage->hasFolderInFolder($segment, $current)
                ? $storage->getFolder(rtrim($current->getIdentifier(), '/') . '/' . $segment . '/')
                : $storage->createFolder($segment, $current);
        }

        return $current;
    }

    private function getIntSetting(string $key, int $default): int
    {
        try {
            $value = (int)$this->extensionConfiguration->get(self::EXTENSION_KEY, $key);
        } catch (\Exception $exception) {
            return $default;
        }

        return $value > 0 ? $value : $default;
    }
}
