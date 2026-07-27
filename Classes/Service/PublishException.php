<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

/**
 * Thrown by DocumentSetPublisher when publication fails at any stage (FAL import or page
 * creation). The document set is left in CONFIRMED status (not reverted) so a retry can pick
 * up where it left off - see DocumentSetPublisher::publish()'s idempotency guard.
 */
class PublishException extends \RuntimeException
{
}
