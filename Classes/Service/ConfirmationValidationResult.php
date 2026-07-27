<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

final class ConfirmationValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors,
    ) {
    }
}
