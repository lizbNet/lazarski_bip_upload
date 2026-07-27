<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Repository;

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class DocumentSetRepository extends Repository
{
    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @return array<int, \PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet>
     */
    public function findOpenByBackendUser(int $backendUserUid): array
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('cruserId', $backendUserUid),
                $query->logicalOr(
                    $query->equals('status', DocumentSetStatus::DRAFT->value),
                    $query->equals('status', DocumentSetStatus::STAGED->value)
                )
            )
        );
        $query->setOrderings(['crdate' => QueryInterface::ORDER_DESCENDING]);

        return $query->execute()->toArray();
    }

    /**
     * @return array<int, \PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet>
     */
    public function findExpired(int $referenceTimestamp): array
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->logicalOr(
                    $query->equals('status', DocumentSetStatus::DRAFT->value),
                    $query->equals('status', DocumentSetStatus::STAGED->value)
                ),
                $query->lessThan('expiresAt', $referenceTimestamp),
                $query->greaterThan('expiresAt', 0)
            )
        );

        return $query->execute()->toArray();
    }
}
