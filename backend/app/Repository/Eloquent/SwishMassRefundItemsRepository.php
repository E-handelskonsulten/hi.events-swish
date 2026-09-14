<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SwishMassRefundItemDomainObject;
use HiEvents\Models\SwishMassRefundItem;
use HiEvents\Repository\Interfaces\SwishMassRefundItemsRepositoryInterface;

/**
 * @extends BaseRepository<SwishMassRefundItemDomainObject>
 */
class SwishMassRefundItemsRepository extends BaseRepository implements SwishMassRefundItemsRepositoryInterface
{
    protected function getModel(): string
    {
        return SwishMassRefundItem::class;
    }

    public function getDomainObject(): string
    {
        return SwishMassRefundItemDomainObject::class;
    }
}
