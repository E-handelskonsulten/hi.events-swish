<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Models\SwishPayment;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;

/**
 * @extends BaseRepository<SwishPaymentDomainObject>
 */
class SwishPaymentsRepository extends BaseRepository implements SwishPaymentsRepositoryInterface
{
    protected function getModel(): string
    {
        return SwishPayment::class;
    }

    public function getDomainObject(): string
    {
        return SwishPaymentDomainObject::class;
    }

    public function findLatestForOrder(int $orderId): ?SwishPaymentDomainObject
    {
        return $this->runQuery(fn () => $this->handleSingleResult(
            $this->model->where('order_id', $orderId)->orderByDesc('id')->first()
        ));
    }
}
