<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\UserDomainObject;

class StartSwishMassRefundDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $accountId,
        public readonly UserDomainObject $initiatedBy,
        public readonly string $confirmation,
        public readonly bool $notifyBuyers,
        public readonly bool $cancelOrders,
    ) {}
}
