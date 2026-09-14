<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SwishMassRefundItemStatus: string
{
    use BaseEnum;

    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case REQUESTED = 'REQUESTED';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';

    /**
     * @return string[]
     */
    public static function openStatuses(): array
    {
        return [self::PENDING->value, self::PROCESSING->value, self::REQUESTED->value];
    }
}
