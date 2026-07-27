<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Repository;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class DocumentItemRepository extends Repository
{
    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @return array<int, \PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem>
     */
    public function findByDocumentSet(int $documentSetUid): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('documentSet', $documentSetUid));
        $query->setOrderings(['uid' => QueryInterface::ORDER_ASCENDING]);

        return $query->execute()->toArray();
    }

    public function countByDocumentSet(int $documentSetUid): int
    {
        $query = $this->createQuery();
        $query->matching($query->equals('documentSet', $documentSetUid));

        return $query->execute()->count();
    }
}
