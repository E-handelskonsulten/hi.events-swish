<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\EventOccurrenceStatus;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;

class TicketSmsSendTimeResolver
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly EventOccurrenceRepositoryInterface $eventOccurrenceRepository,
    ) {}

    public function resolve(int $orderId, int $eventId, ?int $leadHours): ?CarbonImmutable
    {
        if ($leadHours === null) {
            return null;
        }

        return $this->firstStart($orderId, $eventId)?->subHours($leadHours);
    }

    private function firstStart(int $orderId, int $eventId): ?CarbonImmutable
    {
        $occurrenceIds = $this->orderItemRepository
            ->findWhere([OrderItemDomainObjectAbstract::ORDER_ID => $orderId])
            ->map(fn (OrderItemDomainObject $item) => $item->getEventOccurrenceId())
            ->filter()
            ->unique()
            ->values()
            ->all();

        $occurrences = $occurrenceIds !== []
            ? $this->eventOccurrenceRepository->findWhereIn(EventOccurrenceDomainObjectAbstract::ID, $occurrenceIds)
            : $this->eventOccurrenceRepository->findWhere([
                EventOccurrenceDomainObjectAbstract::EVENT_ID => $eventId,
                EventOccurrenceDomainObjectAbstract::STATUS => EventOccurrenceStatus::ACTIVE->name,
            ]);

        $earliest = $occurrences
            ->map(fn (EventOccurrenceDomainObject $occurrence) => CarbonImmutable::parse($occurrence->getStartDate(), 'UTC'))
            ->sort()
            ->first();

        return $earliest ?: null;
    }
}
