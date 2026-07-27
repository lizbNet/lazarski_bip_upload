<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * Thrown when a confirmed PDF cannot be imported into FAL. Always caught by
 * DocumentSetPublisher, which rolls back any already-imported files before rethrowing as
 * PublishException with an editor-safe message.
 */
class FalImportException extends \RuntimeException
{
}
