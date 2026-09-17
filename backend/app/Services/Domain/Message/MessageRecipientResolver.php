<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Message;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\MessagePurpose;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Message\DTO\MessageRecipientsDTO;
use HiEvents\Services\Domain\Message\DTO\SmsRecipientDTO;
use Illuminate\Support\Collection;

class MessageRecipientResolver
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
    ) {}

    public function resolve(SendMessageDTO $messageData): MessageRecipientsDTO
    {
        $marketing = $messageData->purpose === MessagePurpose::MARKETING;
        $attendees = $this->attendees($messageData);
        $orders = $this->orders($messageData, $attendees);

        $consented = $orders->filter(fn (OrderDomainObject $order) => $order->getOptedIntoMarketingAt() !== null);
        $eligibleOrders = $marketing ? $consented : $orders;
        $eligibleOrderIds = $eligibleOrders->map(fn (OrderDomainObject $order) => $order->getId())->all();

        $emails = $attendees !== null
            ? $attendees
                ->filter(fn (AttendeeDomainObject $attendee) => in_array($attendee->getOrderId(), $eligibleOrderIds, true))
                ->map(fn (AttendeeDomainObject $attendee) => $attendee->getEmail())
            : $eligibleOrders->map(fn (OrderDomainObject $order) => $order->getEmail());

        $phones = $this->phonesByOrder($orders);
        $smsRecipients = $eligibleOrders
            ->filter(fn (OrderDomainObject $order) => isset($phones[$order->getId()]))
            ->map(fn (OrderDomainObject $order) => new SmsRecipientDTO($order->getId(), $phones[$order->getId()]))
            ->unique(fn (SmsRecipientDTO $recipient) => $recipient->phone)
            ->values();

        return new MessageRecipientsDTO(
            emailRecipients: $emails->filter()->unique()->count(),
            smsRecipients: $smsRecipients,
            excludedWithoutConsent: $marketing ? $orders->count() - $consented->count() : 0,
            excludedWithoutPhone: $eligibleOrders->count() - $eligibleOrders->filter(fn (OrderDomainObject $order) => isset($phones[$order->getId()]))->count(),
            consentedEmails: $consented
                ->map(fn (OrderDomainObject $order) => strtolower((string) $order->getEmail()))
                ->merge($attendees?->filter(fn (AttendeeDomainObject $attendee) => in_array($attendee->getOrderId(), $consented->map(fn (OrderDomainObject $order) => $order->getId())->all(), true))
                    ->map(fn (AttendeeDomainObject $attendee) => strtolower((string) $attendee->getEmail())) ?? collect())
                ->filter()
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @return Collection<int, AttendeeDomainObject>|null null when the scope targets order owners directly
     */
    private function attendees(SendMessageDTO $messageData): ?Collection
    {
        $columns = ['id', 'order_id', 'email', 'status', 'product_id', 'event_occurrence_id'];

        return match ($messageData->type) {
            MessageTypeEnum::INDIVIDUAL_ATTENDEES => $this->attendeeRepository->findWhereIn(
                field: AttendeeDomainObjectAbstract::ID,
                values: $messageData->attendee_ids ?? [],
                additionalWhere: [AttendeeDomainObjectAbstract::EVENT_ID => $messageData->event_id],
                columns: $columns,
            ),
            MessageTypeEnum::TICKET_HOLDERS => $this->attendeeRepository->findWhereIn(
                field: AttendeeDomainObjectAbstract::PRODUCT_ID,
                values: $messageData->product_ids ?? [],
                additionalWhere: array_merge([
                    AttendeeDomainObjectAbstract::EVENT_ID => $messageData->event_id,
                    AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
                ], $this->occurrenceWhere($messageData)),
                columns: $columns,
            ),
            MessageTypeEnum::ALL_ATTENDEES => $this->attendeeRepository->findWhere(
                where: array_merge([
                    AttendeeDomainObjectAbstract::EVENT_ID => $messageData->event_id,
                    AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
                ], $this->occurrenceWhere($messageData)),
                columns: $columns,
            ),
            default => null,
        };
    }

    /**
     * @return Collection<int, OrderDomainObject>
     */
    private function orders(SendMessageDTO $messageData, ?Collection $attendees): Collection
    {
        if ($attendees !== null) {
            $orderIds = $attendees->map(fn (AttendeeDomainObject $attendee) => $attendee->getOrderId())->unique()->values()->all();

            return $orderIds === [] ? collect() : $this->orderRepository->findWhereIn(OrderDomainObjectAbstract::ID, $orderIds);
        }

        return match ($messageData->type) {
            MessageTypeEnum::ORDER_OWNER => collect(array_filter([$this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::ID => $messageData->order_id,
                OrderDomainObjectAbstract::EVENT_ID => $messageData->event_id,
            ])])),
            MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT => $this->orderRepository->findOrdersAssociatedWithProducts(
                eventId: $messageData->event_id,
                productIds: $messageData->product_ids ?? [],
                orderStatuses: $messageData->order_statuses ?: ['COMPLETED'],
                eventOccurrenceId: $messageData->event_occurrence_id,
                eventOccurrenceIds: $messageData->event_occurrence_ids,
            ),
            default => collect(),
        };
    }

    /**
     * @param  Collection<int, OrderDomainObject>  $orders
     * @return array<int, string> E.164 phone keyed by order id
     */
    private function phonesByOrder(Collection $orders): array
    {
        $orderIds = $orders->map(fn (OrderDomainObject $order) => $order->getId())->all();

        $paidAliases = $orderIds === [] ? collect() : $this->swishPaymentsRepository
            ->findWhereIn(SwishPaymentDomainObjectAbstract::ORDER_ID, $orderIds, [
                SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::PAID->value,
            ])
            ->filter(fn (SwishPaymentDomainObject $payment) => (bool) $payment->getPayerAlias())
            ->keyBy(fn (SwishPaymentDomainObject $payment) => $payment->getOrderId());

        $phones = [];

        foreach ($orders as $order) {
            $alias = $order->getPhone() ?: $paidAliases->get($order->getId())?->getPayerAlias();

            if ($alias) {
                $phones[$order->getId()] = '+'.$alias;
            }
        }

        return $phones;
    }

    private function occurrenceWhere(SendMessageDTO $messageData): array
    {
        if (! empty($messageData->event_occurrence_ids)) {
            return [[AttendeeDomainObjectAbstract::EVENT_OCCURRENCE_ID, 'in', $messageData->event_occurrence_ids]];
        }

        if ($messageData->event_occurrence_id !== null) {
            return [AttendeeDomainObjectAbstract::EVENT_OCCURRENCE_ID => $messageData->event_occurrence_id];
        }

        return [];
    }
}
