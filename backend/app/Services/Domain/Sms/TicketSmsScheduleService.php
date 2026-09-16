<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Jobs\Sms\SendOrderSmsJob;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;

class TicketSmsScheduleService
{
    public function __construct(
        private readonly SmsMessagesRepositoryInterface $smsMessagesRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly TicketSmsSendTimeResolver $sendTimeResolver,
        private readonly Dispatcher $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchDue(): int
    {
        $due = $this->smsMessagesRepository->findDueScheduled(now());

        foreach ($due as $message) {
            $this->bus->dispatch(new SendOrderSmsJob($message->getOrderId(), SmsMessageType::from($message->getType())));
        }

        return $due->count();
    }

    public function cancelForOrder(int $orderId): void
    {
        $cancelled = $this->smsMessagesRepository->updateWhere(
            attributes: [SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::CANCELLED->name],
            where: [
                SmsMessageDomainObjectAbstract::ORDER_ID => $orderId,
                SmsMessageDomainObjectAbstract::TYPE => SmsMessageType::TICKET->name,
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::SCHEDULED->name,
            ],
        );

        if ($cancelled > 0) {
            $this->logger->info('Scheduled ticket SMS cancelled after refund', ['order_id' => $orderId]);
        }
    }

    /**
     * @param  array<string, mixed>  $where  narrows the scheduled rows, e.g. by event_id or organizer_id
     */
    public function reschedule(array $where): int
    {
        $pending = $this->smsMessagesRepository->findWhere($where + [
            SmsMessageDomainObjectAbstract::TYPE => SmsMessageType::TICKET->name,
            SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::SCHEDULED->name,
        ]);

        $leadHoursByOrganizer = [];
        $moved = 0;

        /** @var SmsMessageDomainObject $message */
        foreach ($pending as $message) {
            $organizerId = $message->getOrganizerId();
            if (! array_key_exists($organizerId, $leadHoursByOrganizer)) {
                $leadHours = $this->organizerBillingSettingsRepository->findFirstWhere([
                    OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                ])?->getSmsLeadHours();
                $leadHoursByOrganizer[$organizerId] = $leadHours === null ? null : (int) $leadHours;
            }

            $sendAt = $this->sendTimeResolver->resolve($message->getOrderId(), $message->getEventId(), $leadHoursByOrganizer[$organizerId]);

            $scheduledFor = $sendAt?->isFuture() ? $sendAt->toDateTimeString() : now()->toDateTimeString();

            if ($scheduledFor === $message->getScheduledFor()) {
                continue;
            }

            $this->smsMessagesRepository->updateFromArray($message->getId(), [
                SmsMessageDomainObjectAbstract::SCHEDULED_FOR => $scheduledFor,
            ]);
            $moved++;
        }

        if ($moved > 0) {
            $this->logger->info('Scheduled ticket SMS moved', ['where' => $where, 'moved' => $moved]);
        }

        return $moved;
    }
}
