<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\BillingSummaryRunDomainObject;
use HiEvents\Models\BillingSummaryRun;
use HiEvents\Repository\Interfaces\BillingSummaryRunsRepositoryInterface;

/**
 * @extends BaseRepository<BillingSummaryRunDomainObject>
 */
class BillingSummaryRunsRepository extends BaseRepository implements BillingSummaryRunsRepositoryInterface
{
    protected function getModel(): string
    {
        return BillingSummaryRun::class;
    }

    public function getDomainObject(): string
    {
        return BillingSummaryRunDomainObject::class;
    }
}
