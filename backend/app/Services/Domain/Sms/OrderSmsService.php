<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\Sms\ElksSmsClient;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class OrderSmsService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly SmsMessagesRepositoryInterface $smsMessagesRepository,
        private readonly ElksSmsClient $smsClient,
        private readonly SmsMessageBuilder $messageBuilder,
        private readonly TicketSmsSendTimeResolver $sendTimeResolver,
        private readonly TicketSmsScheduleService $scheduleService,
        private readonly SmsRecipientGuard $recipientGuard,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws SmsDeliveryException
     */
    public function send(int $orderId, SmsMessageType $type): void
    {
        if (! $this->config->get('sms.enabled')) {
            return;
        }

        $order = $this->orderRepository->findById($orderId);
        $event = $this->eventRepository->findById($order->getEventId());

        if ($type === SmsMessageType::REFUND_NOTICE) {
            if (! $order->isFullyRefunded()) {
                return;
            }

            $this->scheduleService->cancelForOrder($orderId);
        }

        $settings = $this->findSettings($event);

        if ($settings === null || ! $settings->getSmsEnabled()) {
            return;
        }

        $existing = $this->smsMessagesRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::ORDER_ID => $orderId,
            SmsMessageDomainObjectAbstract::TYPE => $type->name,
        ]);

        if (in_array($existing?->getStatus(), [SmsMessageStatus::SENT->name, SmsMessageStatus::CANCELLED->name], true)) {
            return;
        }

        $recipient = $this->resolveRecipient($order);

        if ($recipient === null) {
            $this->logger->info('SMS skipped: order has no mobile number', [
                'order_id' => $orderId,
                'type' => $type->name,
            ]);

            return;
        }

        $sender = $settings->getSmsSenderName() ?: $this->config->get('sms.default_sender');

        if ($this->recipientGuard->refuse($recipient, ['order_id' => $orderId, 'type' => $type->name])) {
            $this->record($existing, $event, $order, $type, [
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::CANCELLED->name,
                SmsMessageDomainObjectAbstract::RECIPIENT => $recipient,
                SmsMessageDomainObjectAbstract::SENDER => $sender,
                SmsMessageDomainObjectAbstract::ERROR_MESSAGE => SmsRecipientGuard::reason(),
            ]);

            return;
        }

        if ($type === SmsMessageType::TICKET) {
            $leadHours = $settings->getSmsLeadHours();
            $sendAt = $this->sendTimeResolver->resolve($orderId, $event->getId(), $leadHours === null ? null : (int) $leadHours);

            if ($sendAt !== null && $sendAt->isFuture()) {
                $this->record($existing, $event, $order, $type, [
                    SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::SCHEDULED->name,
                    SmsMessageDomainObjectAbstract::RECIPIENT => $recipient,
                    SmsMessageDomainObjectAbstract::SENDER => $sender,
                    SmsMessageDomainObjectAbstract::SCHEDULED_FOR => $sendAt->toDateTimeString(),
                ]);

                $this->logger->info('Ticket SMS scheduled', [
                    'order_id' => $orderId,
                    'scheduled_for' => $sendAt->toIso8601String(),
                    'lead_hours' => $settings->getSmsLeadHours(),
                ]);

                return;
            }
        }

        $message = $this->messageBuilder->build($type, $order, $event, $this->attendees($order));

        try {
            $sent = $this->smsClient->send($recipient, $sender, $message);
        } catch (SmsDeliveryException $exception) {
            $this->record($existing, $event, $order, $type, [
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::FAILED->name,
                SmsMessageDomainObjectAbstract::RECIPIENT => $recipient,
                SmsMessageDomainObjectAbstract::SENDER => $sender,
                SmsMessageDomainObjectAbstract::ERROR_MESSAGE => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }

        $this->record($existing, $event, $order, $type, [
            SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::SENT->name,
            SmsMessageDomainObjectAbstract::RECIPIENT => $recipient,
            SmsMessageDomainObjectAbstract::SENDER => $sender,
            SmsMessageDomainObjectAbstract::PARTS => $sent->parts,
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $sent->providerMessageId ?: null,
            SmsMessageDomainObjectAbstract::ERROR_MESSAGE => null,
            SmsMessageDomainObjectAbstract::SENT_AT => now()->toDateTimeString(),
        ]);

        $this->logger->info('SMS sent', [
            'order_id' => $orderId,
            'type' => $type->name,
            'organizer_id' => $event->getOrganizerId(),
            'parts' => $sent->parts,
            'dry_run' => $sent->dryRun,
        ]);
    }

    private function findSettings(EventDomainObject $event): ?OrganizerBillingSettingDomainObject
    {
        return $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
        ]);
    }

    /**
     * @return Collection<int, AttendeeDomainObject>
     */
    private function attendees(OrderDomainObject $order): Collection
    {
        return $this->attendeeRepository
            ->findWhere([AttendeeDomainObjectAbstract::ORDER_ID => $order->getId()])
            ->sortBy(fn (AttendeeDomainObject $attendee) => $attendee->getId())
            ->values();
    }

    private function resolveRecipient(OrderDomainObject $order): ?string
    {
        // The number the buyer typed in checkout wins: the Swish payer may be a
        // friend paying, or a sandbox alias. Fall back to the payer alias.
        if ($order->getPhone()) {
            return '+'.$order->getPhone();
        }

        $payment = $this->swishPaymentsRepository->findLatestForOrder($order->getId());

        if ($payment !== null && $payment->getStatus() === SwishPaymentStatus::PAID->value && $payment->getPayerAlias()) {
            return '+'.$payment->getPayerAlias();
        }

        return null;
    }

    private function record(
        ?SmsMessageDomainObject $existing,
        EventDomainObject $event,
        OrderDomainObject $order,
        SmsMessageType $type,
        array $data,
    ): void {
        if ($existing !== null) {
            $this->smsMessagesRepository->updateFromArray($existing->getId(), $data);

            return;
        }

        $this->smsMessagesRepository->create($data + [
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
            SmsMessageDomainObjectAbstract::EVENT_ID => $event->getId(),
            SmsMessageDomainObjectAbstract::ORDER_ID => $order->getId(),
            SmsMessageDomainObjectAbstract::TYPE => $type->name,
        ]);
    }
}
