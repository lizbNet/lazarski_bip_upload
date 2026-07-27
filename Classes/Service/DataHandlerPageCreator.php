<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the hidden BIP page and its ordered pages.media attachments in a single DataHandler
 * call, following the explicit-sys_file_reference-children pattern documented and
 * battle-tested in scripts/migration/post-import-fixups.php (lines 177-291): passing a bare
 * comma-separated sys_file uid list to a type=file field is ambiguous and can cause DataHandler
 * to reinterpret and reparent EXISTING, unrelated sys_file_reference rows onto this page
 * instead of creating new ones. Explicit NEW-id child records in the same datamap/call sidestep
 * that risk entirely, since a NEW id can never collide with a real uid.
 *
 * Runs as an admin DataHandler call (matching the same migration-script precedent): the editor
 * triggering this has already passed the module's own access/destination checks
 * (DocumentSetAccessGuard, DestinationResolver::isAllowedDestination against the configured BIP
 * root), so this is a controlled, pre-validated page creation, not an open-ended one.
 */
class DataHandlerPageCreator implements PageCreatorInterface
{
    public function createHiddenPage(array $pageFields, array $orderedFileUids): int
    {
        $pageNewId = 'NEWlbupage';

        $refRows = [];
        $refIds = [];
        foreach (array_values($orderedFileUids) as $index => $fileUid) {
            // No underscore in the NEW id: DataHandler::processRemapStack() splits values
            // referenced from inside a type=file value list on '_', treating everything
            // before the last segment as a "tablename_" prefix - an id like
            // "NEWlbupage_ref0" gets mis-split and silently resolves to nothing. Same
            // documented gotcha as the migration script's NEW-id convention.
            $refId = sprintf('NEWlburef%d', $index);
            $refIds[] = $refId;
            $refRows[$refId] = [
                'table_local' => 'sys_file',
                'uid_local' => $fileUid,
                'tablenames' => 'pages',
                'fieldname' => 'media',
                'pid' => $pageNewId,
            ];
        }

        $pageRow = $pageFields;
        $pageRow['media'] = implode(',', $refIds);

        $datamap = ['pages' => [$pageNewId => $pageRow]];
        if ($refRows !== []) {
            $datamap['sys_file_reference'] = $refRows;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->admin = true;
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            throw new PageCreationException('DataHandler: ' . implode('; ', $dataHandler->errorLog));
        }

        $newPageUid = $dataHandler->substNEWwithIDs[$pageNewId] ?? null;
        if (!is_numeric($newPageUid) || (int)$newPageUid <= 0) {
            throw new PageCreationException('DataHandler did not report a created page uid.');
        }

        return (int)$newPageUid;
    }
}
