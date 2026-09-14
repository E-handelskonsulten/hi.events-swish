<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SwishPaymentDomainObject;

/**
 * @extends RepositoryInterface<SwishPaymentDomainObject>
 */
interface SwishPaymentsRepositoryInterface extends RepositoryInterface
{
    public function findLatestForOrder(int $orderId): ?SwishPaymentDomainObject;
}
