<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Detects whether a candidate slug already collides with an existing page under the chosen
 * parent. Step 3 only detects/blocks on collision so the editor can adjust it on the review
 * form; automatic collision resolution (via TYPO3's own SlugHelper) belongs to actual page
 * creation in a later delivery step.
 */
class PageSlugChecker
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function isSlugAvailable(int $parentPageUid, string $slug): bool
    {
        if ($slug === '') {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $count = $queryBuilder->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentPageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count === 0;
    }
}
