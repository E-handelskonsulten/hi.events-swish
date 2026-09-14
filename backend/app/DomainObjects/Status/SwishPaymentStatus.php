<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SwishPaymentStatus: string
{
    use BaseEnum;

    case CREATED = 'CREATED';
    case PAID = 'PAID';
    case DECLINED = 'DECLINED';
    case ERROR = 'ERROR';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case PAID_FLAGGED = 'PAID_FLAGGED';

    public static function fromSwishStatus(?string $status): ?self
    {
        return $status === null ? null : self::tryFrom(strtoupper($status));
    }

    public function isTerminal(): bool
    {
        return $this !== self::CREATED;
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::DECLINED, self::ERROR, self::CANCELLED], true);
    }
}
