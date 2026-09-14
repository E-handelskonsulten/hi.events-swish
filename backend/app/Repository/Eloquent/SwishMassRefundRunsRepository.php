<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Models\SwishMassRefundRun;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;

/**
 * @extends BaseRepository<SwishMassRefundRunDomainObject>
 */
class SwishMassRefundRunsRepository extends BaseRepository implements SwishMassRefundRunsRepositoryInterface
{
    protected function getModel(): string
    {
        return SwishMassRefundRun::class;
    }

    public function getDomainObject(): string
    {
        return SwishMassRefundRunDomainObject::class;
    }
}
