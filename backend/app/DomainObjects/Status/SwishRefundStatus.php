<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SwishRefundStatus: string
{
    use BaseEnum;

    case CREATED = 'CREATED';
    case DEBITED = 'DEBITED';
    case PAID = 'PAID';
    case ERROR = 'ERROR';

    public static function fromSwishStatus(?string $status): ?self
    {
        return $status === null ? null : self::tryFrom(strtoupper($status));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::PAID, self::ERROR], true);
    }
}
