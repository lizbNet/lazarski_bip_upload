<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Model;

enum DocumentItemStatus: int
{
    case UPLOADED = 0;
    case CONVERTED = 1;
    case FAILED = 2;
    case READY = 3;
    case PUBLISHED = 4;
}
