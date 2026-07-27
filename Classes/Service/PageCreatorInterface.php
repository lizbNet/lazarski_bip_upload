<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * Seam between DocumentSetPublisher and the actual DataHandler-based page creation, so
 * publication logic can be tested without a bootstrapped TYPO3 DataHandler/DB.
 */
interface PageCreatorInterface
{
    /**
     * Creates a hidden page and its ordered pages.media sys_file_reference children in one
     * atomic DataHandler call.
     *
     * @param array{pid: int, title: string, subtitle: string, slug: string, hidden: int, doktype: int} $pageFields
     * @param int[] $orderedFileUids sys_file uids, in the desired pages.media order
     * @throws PageCreationException
     */
    public function createHiddenPage(array $pageFields, array $orderedFileUids): int;
}
