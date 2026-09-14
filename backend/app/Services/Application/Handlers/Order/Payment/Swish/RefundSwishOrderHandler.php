<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Swish\SwishRefundService;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Throwable;

class RefundSwishOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly SwishRefundService $swishRefundService,
        private readonly OrderCancelService $orderCancelService,
        private readonly Mailer $mailer,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws ResourceNotFoundException
     * @throws SwishApiException
     * @throws SwishConfigurationException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);
        $payment = $this->fetchPaidPayment($order);

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());

        $this->validateRefundability($order, $amount);

        /** @var EventDomainObject $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($refundOrderDTO->event_id);

        $connection = $this->swishConfigurationService->resolveForOrganizer($event->getOrganizerId());

        if ($refundOrderDTO->cancel_order && ! $order->isOrderCancelled()) {
            $this->orderCancelService->cancelOrder($order);
        }

        $this->swishRefundService->createRefund($order, $payment, $amount, $connection);

        if ($refundOrderDTO->notify_buyer && $order->getEmail() !== null) {
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderRefunded(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                    refundAmount: $amount,
                ));
        }

        return $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
        ]);
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::ID => $orderId,
        ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                'id' => $orderId,
                'eventId' => $eventId,
            ]));
        }

        return $order;
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function fetchPaidPayment(OrderDomainObject $order): SwishPaymentDomainObject
    {
        $payment = $this->swishPaymentsRepository->findFirstWhere([
            SwishPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::PAID->value,
        ]);

        if ($payment === null || $payment->getPaymentReference() === null) {
            throw new RefundNotPossibleException(__('There is no completed Swish payment associated with this order.'));
        }

        return $payment;
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function validateRefundability(OrderDomainObject $order, MoneyValue $amount): void
    {
        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order. Please wait for the refund to be processed before requesting another one.')
            );
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUNDED->name) {
            throw new RefundNotPossibleException(__('This order has already been fully refunded.'));
        }

        if ($amount->toFloat() < 1) {
            throw new RefundNotPossibleException(__('Swish refunds must be at least 1 SEK.'));
        }

        if ($amount->toFloat() > $order->getTotalGross() - $order->getTotalRefunded() + 0.001) {
            throw new RefundNotPossibleException(__('The refund amount cannot exceed the amount available to refund.'));
        }
    }
}
