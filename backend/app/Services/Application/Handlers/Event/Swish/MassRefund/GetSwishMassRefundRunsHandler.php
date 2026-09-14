<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund;

use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use Illuminate\Support\Collection;

class GetSwishMassRefundRunsHandler
{
    private const MAX_RUNS = 20;

    public function __construct(
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
    ) {}

    /**
     * @return Collection<int, SwishMassRefundRunDomainObject>
     */
    public function handle(int $eventId): Collection
    {
        return $this->runsRepository
            ->findWhere([SwishMassRefundRunDomainObjectAbstract::EVENT_ID => $eventId])
            ->sortByDesc(fn (SwishMassRefundRunDomainObject $run) => $run->getId())
            ->take(self::MAX_RUNS)
            ->values();
    }
}
