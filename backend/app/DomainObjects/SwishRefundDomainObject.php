<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Status\SwishRefundStatus;

class SwishRefundDomainObject extends Generated\SwishRefundDomainObjectAbstract
{
    public function getStatusEnum(): SwishRefundStatus
    {
        return SwishRefundStatus::from($this->getStatus());
    }

    public function isTerminal(): bool
    {
        return $this->getStatusEnum()->isTerminal();
    }
}
