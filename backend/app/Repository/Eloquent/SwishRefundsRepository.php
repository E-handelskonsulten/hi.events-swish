<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Models\SwishRefund;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;

/**
 * @extends BaseRepository<SwishRefundDomainObject>
 */
class SwishRefundsRepository extends BaseRepository implements SwishRefundsRepositoryInterface
{
    protected function getModel(): string
    {
        return SwishRefund::class;
    }

    public function getDomainObject(): string
    {
        return SwishRefundDomainObject::class;
    }
}
