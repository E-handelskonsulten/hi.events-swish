<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund;

use HiEvents\DomainObjects\Generated\SwishMassRefundItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\SwishMassRefundItemDomainObject;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SwishMassRefundItemsRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;

class GetSwishMassRefundRunHandler
{
    public function __construct(
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
        private readonly SwishMassRefundItemsRepositoryInterface $itemsRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, int $runId): SwishMassRefundRunDomainObject
    {
        /** @var SwishMassRefundRunDomainObject|null $run */
        $run = $this->runsRepository->findFirstWhere([
            SwishMassRefundRunDomainObjectAbstract::ID => $runId,
            SwishMassRefundRunDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($run === null) {
            throw new ResourceNotFoundException(__('Mass refund not found.'));
        }

        $items = $this->itemsRepository
            ->findWhere([SwishMassRefundItemDomainObjectAbstract::RUN_ID => $run->getId()])
            ->sortBy(fn (SwishMassRefundItemDomainObject $item) => $item->getId())
            ->values();

        return $run->setItems($items);
    }
}
