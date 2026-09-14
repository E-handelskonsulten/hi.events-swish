<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SwishMassRefundRunStatus: string
{
    use BaseEnum;

    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';

    public function isActive(): bool
    {
        return $this !== self::COMPLETED;
    }
}
