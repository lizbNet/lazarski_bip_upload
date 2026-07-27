<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * Thrown when DataHandler fails to create the hidden BIP page (and its sys_file_reference
 * media children). Always caught by DocumentSetPublisher, which rolls back any already-imported
 * FAL files before rethrowing as PublishException with an editor-safe message.
 */
class PageCreationException extends \RuntimeException
{
}
