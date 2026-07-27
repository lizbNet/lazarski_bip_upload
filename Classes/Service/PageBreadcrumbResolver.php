<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Builds a human-readable "BIP > Section > Subsection > Target" breadcrumb for a candidate
 * destination page, purely so the review screen can show an editor where a set's new hidden
 * page would actually appear in the page tree - display only, no bearing on
 * DestinationResolver::isAllowedDestination()'s own validation.
 */
class PageBreadcrumbResolver
{
    public function resolve(int $pageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        $titles = [];
        foreach (BackendUtility::BEgetRootLine($pageUid) as $page) {
            $title = trim((string)($page['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return implode(' > ', $titles);
    }
}
