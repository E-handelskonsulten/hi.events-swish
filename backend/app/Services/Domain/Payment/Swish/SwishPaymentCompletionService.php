<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Mail\Swish\SwishPaymentNeedsReviewMail;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Order\OccurrenceStatusValidator;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishPaymentRequestDTO;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishPaymentCompletionService
{
    public const FLAG_ORDER_NOT_PAYABLE = 'order_not_payable';

    public const FLAG_OCCURRENCE_UNAVAILABLE = 'occurrence_unavailable';

    public const FLAG_RESERVATION_EXPIRED_NO_INVENTORY = 'reservation_expired_no_inventory';

    public const FLAG_AMOUNT_MISMATCH = 'amount_or_payee_mismatch';

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ProductQuantityUpdateService $productQuantityUpdateService,
        private readonly AvailableProductQuantitiesFetchService $availableProductQuantitiesFetchService,
        private readonly OccurrenceStatusValidator $occurrenceStatusValidator,
        private readonly OrderApplicationFeeService $orderApplicationFeeService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function complete(SwishPaymentDomainObject $payment, SwishPaymentRequestDTO $remote): SwishPaymentDomainObject
    {
        $result = $this->databaseManager->transaction(function () use ($payment, $remote) {
            /** @var OrderDomainObject $order */
            $order = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->findById($payment->getOrderId());

            $this->databaseManager->statement('SELECT pg_advisory_xact_lock(?)', [$order->getEventId()]);

            /** @var SwishPaymentDomainObject $freshPayment */
            $freshPayment = $this->swishPaymentsRepository->findById($payment->getId());

            if ($freshPayment->isTerminal()) {
                $this->logger->info('Swish payment already in terminal state, skipping completion', [
                    'swish_payment_id' => $freshPayment->getId(),
                    'order_id' => $order->getId(),
                    'status' => $freshPayment->getStatus(),
                ]);

                return null;
            }

            $flagReason = $this->findBlockingReason($order);

            if ($flagReason !== null) {
                return ['flagged' => $this->flag($freshPayment, $order, $remote, $flagReason)];
            }

            $updatedOrder = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                    OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                    OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::SWISH->value,
                ]);

            if ($updatedOrder->getAffiliateId()) {
                $this->affiliateRepository->incrementSales(
                    affiliateId: $updatedOrder->getAffiliateId(),
                    amount: $updatedOrder->getTotalGross(),
                );
            }

            $this->attendeeRepository->updateWhere(
                attributes: ['status' => AttendeeStatus::ACTIVE->name],
                where: [
                    'order_id' => $updatedOrder->getId(),
                    'status' => AttendeeStatus::AWAITING_PAYMENT->name,
                ],
            );

            $this->productQuantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            $this->orderApplicationFeeService->createOrderApplicationFee(
                orderId: $updatedOrder->getId(),
                applicationFeeAmountMinorUnit: 0,
                orderApplicationFeeStatus: OrderApplicationFeeStatus::PAYMENT_WAIVED,
                paymentMethod: PaymentProviders::SWISH,
                currency: $updatedOrder->getCurrency(),
            );

            $paidPayment = $this->swishPaymentsRepository->updateFromArray($freshPayment->getId(), [
                SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::PAID->value,
                SwishPaymentDomainObjectAbstract::PAYMENT_REFERENCE => $remote->paymentReference,
                SwishPaymentDomainObjectAbstract::PAYER_ALIAS => $remote->payerAlias ?? $freshPayment->getPayerAlias(),
                SwishPaymentDomainObjectAbstract::DATE_PAID => $remote->datePaid ?? now()->toDateTimeString(),
                SwishPaymentDomainObjectAbstract::ERROR_CODE => null,
                SwishPaymentDomainObjectAbstract::ERROR_MESSAGE => null,
            ]);

            /** @var EventSettingDomainObject $eventSettings */
            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            $this->logger->info('Swish payment completed order', [
                'swish_payment_id' => $paidPayment->getId(),
                'instruction_uuid' => $paidPayment->getInstructionUuid(),
                'order_id' => $updatedOrder->getId(),
                'status' => SwishPaymentStatus::PAID->value,
                'payment_reference' => $remote->paymentReference,
                'late_payment' => $order->isReservedOrderExpired(),
            ]);

            return ['order' => $updatedOrder, 'eventSettings' => $eventSettings, 'payment' => $paidPayment];
        });

        if ($result === null) {
            return $this->swishPaymentsRepository->findById($payment->getId());
        }

        if (isset($result['flagged'])) {
            return $result['flagged'];
        }

        event(new OrderStatusChangedEvent(
            order: $result['order'],
            createInvoice: $result['eventSettings']->getEnableInvoicing(),
        ));

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_CREATED,
                orderId: $result['order']->getId(),
            ),
        );

        return $result['payment'];
    }

    /**
     * @throws Throwable
     */
    public function flagForReview(SwishPaymentDomainObject $payment, SwishPaymentRequestDTO $remote, string $reason): SwishPaymentDomainObject
    {
        return $this->databaseManager->transaction(function () use ($payment, $remote, $reason) {
            /** @var SwishPaymentDomainObject $freshPayment */
            $freshPayment = $this->swishPaymentsRepository->findById($payment->getId());

            if ($freshPayment->isTerminal()) {
                return $freshPayment;
            }

            $order = $this->orderRepository->findById($freshPayment->getOrderId());

            return $this->flag($freshPayment, $order, $remote, $reason);
        });
    }

    private function findBlockingReason(OrderDomainObject $order): ?string
    {
        $payableStatuses = [
            OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderPaymentStatus::PAYMENT_FAILED->name,
        ];

        if (! in_array($order->getPaymentStatus(), $payableStatuses, true)
            || in_array($order->getStatus(), [OrderStatus::CANCELLED->name, OrderStatus::ABANDONED->name, OrderStatus::COMPLETED->name], true)) {
            return self::FLAG_ORDER_NOT_PAYABLE;
        }

        if ($this->occurrenceStatusValidator->findBlockingOccurrence($order) !== null) {
            return self::FLAG_OCCURRENCE_UNAVAILABLE;
        }

        if ($order->isReservedOrderExpired() && ! $this->inventoryStillAvailable($order)) {
            return self::FLAG_RESERVATION_EXPIRED_NO_INVENTORY;
        }

        return null;
    }

    private function inventoryStillAvailable(OrderDomainObject $order): bool
    {
        /** @var Collection<int, Collection<int, OrderItemDomainObject>> $itemsByOccurrence */
        $itemsByOccurrence = ($order->getOrderItems() ?? collect())
            ->groupBy(fn (OrderItemDomainObject $item) => (string) ($item->getEventOccurrenceId() ?? ''));

        foreach ($itemsByOccurrence as $occurrenceKey => $items) {
            $availability = $this->availableProductQuantitiesFetchService->getAvailableProductQuantities(
                eventId: $order->getEventId(),
                ignoreCache: true,
                eventOccurrenceId: $occurrenceKey === '' ? null : (int) $occurrenceKey,
            );

            $requested = [];
            foreach ($items as $item) {
                $key = $item->getProductId().':'.$item->getProductPriceId();
                $requested[$key] = ($requested[$key] ?? 0) + $item->getQuantity();
            }

            foreach ($requested as $key => $quantity) {
                [$productId, $priceId] = array_map('intval', explode(':', (string) $key));

                $available = $availability->productQuantities
                    ->first(fn (AvailableProductQuantitiesDTO $dto) => $dto->product_id === $productId && $dto->price_id === $priceId)
                    ?->quantity_available ?? 0;

                if ($available !== Constants::INFINITE && $available < $quantity) {
                    return false;
                }
            }

            $ticketQuantity = $items
                ->filter(fn (OrderItemDomainObject $item) => $item->getProductType() === ProductType::TICKET->name)
                ->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());

            if ($availability->occurrence !== null
                && $availability->occurrence->getCapacity() !== null
                && $ticketQuantity > 0) {
                $occurrenceAvailable = $availability->occurrence->getCapacity()
                    - $availability->occurrence->getUsedCapacity()
                    - (int) ($availability->occurrenceReservedQuantity ?? 0);

                if ($occurrenceAvailable < $ticketQuantity) {
                    return false;
                }
            }
        }

        return true;
    }

    private function flag(
        SwishPaymentDomainObject $payment,
        OrderDomainObject $order,
        SwishPaymentRequestDTO $remote,
        string $reason,
    ): SwishPaymentDomainObject {
        $flagged = $this->swishPaymentsRepository->updateFromArray($payment->getId(), [
            SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::PAID_FLAGGED->value,
            SwishPaymentDomainObjectAbstract::FLAG_REASON => $reason,
            SwishPaymentDomainObjectAbstract::PAYMENT_REFERENCE => $remote->paymentReference,
            SwishPaymentDomainObjectAbstract::PAYER_ALIAS => $remote->payerAlias ?? $payment->getPayerAlias(),
            SwishPaymentDomainObjectAbstract::DATE_PAID => $remote->datePaid ?? now()->toDateTimeString(),
            SwishPaymentDomainObjectAbstract::CALLBACK_PAYLOAD => $remote->raw ?: $payment->getCallbackPayload(),
        ]);

        $this->logger->critical('Swish payment received but order could not be completed - manual review required', [
            'swish_payment_id' => $flagged->getId(),
            'instruction_uuid' => $flagged->getInstructionUuid(),
            'order_id' => $order->getId(),
            'order_short_id' => $order->getShortId(),
            'event_id' => $order->getEventId(),
            'status' => SwishPaymentStatus::PAID_FLAGGED->value,
            'reason' => $reason,
            'amount' => $remote->amount,
            'payment_reference' => $remote->paymentReference,
            'payer_alias' => SwishPayerAliasNormalizer::mask($remote->payerAlias),
        ]);

        $this->notifyOrganizer($order, $flagged, $reason);

        return $flagged;
    }

    private function notifyOrganizer(OrderDomainObject $order, SwishPaymentDomainObject $payment, string $reason): void
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->findFirst($order->getEventId());

        $recipient = $event?->getOrganizer()?->getEmail();

        if ($event === null || $recipient === null) {
            $this->logger->error('Could not notify organizer about flagged Swish payment: no organizer email', [
                'order_id' => $order->getId(),
                'swish_payment_id' => $payment->getId(),
            ]);

            return;
        }

        $this->mailer
            ->to($recipient)
            ->locale(config('app.locale'))
            ->send(new SwishPaymentNeedsReviewMail(
                order: $order,
                event: $event,
                payment: $payment,
                reason: $reason,
            ));
    }
}
