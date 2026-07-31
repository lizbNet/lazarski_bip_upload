<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Supplies the admin-configured default author for published BIP pages.
 *
 * Deliberately an institutional string from ext_conf_template.txt rather than the logged-in
 * editor's real name: the value lands in the pages.author field, which the site package renders
 * in the public metryczka, so defaulting to a person would publish a named natural person on
 * every imported page. Sourcing it from configuration keeps that a one-setting decision instead
 * of something baked into already-published pages.
 */
class PageAuthorProvider
{
    private const EXTENSION_KEY = 'lazarski_bip_upload';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function getDefaultPageAuthor(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'defaultPageAuthor'));
        } catch (\Exception $exception) {
            return '';
        }
    }
}
