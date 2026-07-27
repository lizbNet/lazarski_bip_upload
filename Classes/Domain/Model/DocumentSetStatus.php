<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Model;

enum DocumentSetStatus: int
{
    case DRAFT = 0;
    case STAGED = 1;
    case CONFIRMED = 2;
    case PUBLISHED = 3;
    case CANCELLED = 4;
    case EXPIRED = 5;
}
