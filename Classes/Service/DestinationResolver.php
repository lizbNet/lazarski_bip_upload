<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Suggests a parent page per document-set type from admin-configurable settings
 * (ext_conf_template.txt) and validates that an editor-chosen destination lies within the
 * configured BIP page tree, rather than anywhere in the whole installation.
 */
class DestinationResolver
{
    private const EXTENSION_KEY = 'lazarski_bip_upload';

    /**
     * All zarządzenie sub-types (split by issuing authority in TypeClassifier) share the same
     * destination page / FAL folder configuration - the split only matters for the
     * auto-generated FAL subfolder name (see IdentifierSlugBuilder::buildNumberYear()).
     */
    private const ZARZADZENIE_TYPES = [
        'zarzadzenie',
        'zarzadzenie_rektora',
        'zarzadzenie_prezydenta',
        'zarzadzenie_prezydenta_i_rektora',
    ];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function getBipRootPageUid(): int
    {
        return $this->getIntSetting('bipRootPageUid');
    }

    public function suggestParentPageUid(string $type): int
    {
        return self::resolveSuggestedParent(
            $type,
            $this->getIntSetting('parentPageUidUchwala'),
            $this->getIntSetting('parentPageUidZarzadzenie'),
            $this->getIntSetting('parentPageUidProgramStudiow'),
            $this->getIntSetting('defaultParentPageUid'),
            $this->getBipRootPageUid()
        );
    }

    public function suggestFalFolderIdentifier(string $type): string
    {
        return self::resolveSuggestedFalFolder(
            $type,
            $this->getStringSetting('falFolderIdentifierUchwala'),
            $this->getStringSetting('falFolderIdentifierZarzadzenie'),
            $this->getStringSetting('falFolderIdentifierProgramStudiow'),
            $this->getStringSetting('falFolderIdentifier')
        );
    }

    /**
     * Pure decision logic, kept free of DI/configuration reading so it is directly unit
     * testable: given the resolved settings values, which parent page should be suggested?
     */
    public static function resolveSuggestedParent(
        string $type,
        int $parentPageUidUchwala,
        int $parentPageUidZarzadzenie,
        int $parentPageUidProgramStudiow,
        int $defaultParentPageUid,
        int $bipRootPageUid
    ): int {
        $mapping = [
            'uchwala' => $parentPageUidUchwala,
            'zarzadzenie' => $parentPageUidZarzadzenie,
            'program_studiow' => $parentPageUidProgramStudiow,
        ];

        $configKey = in_array($type, self::ZARZADZENIE_TYPES, true) ? 'zarzadzenie' : $type;
        $configured = $mapping[$configKey] ?? 0;
        if ($configured > 0) {
            return $configured;
        }

        return $defaultParentPageUid > 0 ? $defaultParentPageUid : $bipRootPageUid;
    }

    /**
     * Pure decision logic, mirroring resolveSuggestedParent() - given the resolved settings
     * values, which FAL folder should be suggested for this type?
     */
    public static function resolveSuggestedFalFolder(
        string $type,
        string $folderUchwala,
        string $folderZarzadzenie,
        string $folderProgramStudiow,
        string $defaultFolderIdentifier
    ): string {
        $mapping = [
            'uchwala' => trim($folderUchwala),
            'zarzadzenie' => trim($folderZarzadzenie),
            'program_studiow' => trim($folderProgramStudiow),
        ];

        $configKey = in_array($type, self::ZARZADZENIE_TYPES, true) ? 'zarzadzenie' : $type;
        $configured = $mapping[$configKey] ?? '';

        return $configured !== '' ? $configured : trim($defaultFolderIdentifier);
    }

    /**
     * True if $candidatePageUid is the configured BIP root itself or lies anywhere beneath
     * it in the page tree.
     */
    public function isAllowedDestination(int $candidatePageUid): bool
    {
        $bipRoot = $this->getBipRootPageUid();
        if ($bipRoot <= 0 || $candidatePageUid <= 0) {
            return false;
        }
        if ($candidatePageUid === $bipRoot) {
            return true;
        }

        foreach (BackendUtility::BEgetRootLine($candidatePageUid) as $page) {
            if ((int)$page['uid'] === $bipRoot) {
                return true;
            }
        }

        return false;
    }

    private function getIntSetting(string $key): int
    {
        try {
            return (int)$this->extensionConfiguration->get(self::EXTENSION_KEY, $key);
        } catch (\Exception $exception) {
            return 0;
        }
    }

    private function getStringSetting(string $key): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, $key));
        } catch (\Exception $exception) {
            return '';
        }
    }
}
