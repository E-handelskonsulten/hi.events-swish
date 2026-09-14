<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Status\SwishMassRefundItemStatus;

class SwishMassRefundItemDomainObject extends Generated\SwishMassRefundItemDomainObjectAbstract
{
    public function getStatusEnum(): SwishMassRefundItemStatus
    {
        return SwishMassRefundItemStatus::from($this->getStatus());
    }
}
