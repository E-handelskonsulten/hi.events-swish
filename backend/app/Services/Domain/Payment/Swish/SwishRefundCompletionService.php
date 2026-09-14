<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishRefundDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishRefundCompletionService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SwishRefundsRepositoryInterface $swishRefundsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderRefundRepositoryInterface $orderRefundRepository,
        private readonly EventStatisticsRefundService $eventStatisticsRefundService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function complete(SwishRefundDomainObject $refund, SwishRefundDTO $remote): SwishRefundDomainObject
    {
        $result = $this->databaseManager->transaction(function () use ($refund, $remote) {
            /** @var SwishRefundDomainObject $fresh */
            $fresh = $this->swishRefundsRepository->findById($refund->getId());

            if ($fresh->isTerminal()) {
                return ['refund' => $fresh, 'applied' => false];
            }

            /** @var OrderDomainObject $order */
            $order = $this->orderRepository->findById($fresh->getOrderId());

            $alreadyRecorded = $this->orderRefundRepository->findFirstWhere([
                'refund_id' => $fresh->getInstructionUuid(),
            ]);

            if ($alreadyRecorded === null) {
                $refundedAmount = (float) $fresh->getAmount();

                $this->orderRefundRepository->create([
                    'order_id' => $order->getId(),
                    'payment_provider' => PaymentProviders::SWISH->value,
                    'refund_id' => $fresh->getInstructionUuid(),
                    'amount' => $refundedAmount,
                    'currency' => $order->getCurrency(),
                    'status' => 'succeeded',
                    'metadata' => [
                        'swish_payment_reference' => $remote->paymentReference,
                        'original_payment_reference' => $fresh->getOriginalPaymentReference(),
                    ],
                ]);

                $this->orderRepository->increment(
                    id: $order->getId(),
                    column: OrderDomainObjectAbstract::TOTAL_REFUNDED,
                    amount: $refundedAmount,
                );

                $refundStatus = $refundedAmount + $order->getTotalRefunded() >= $order->getTotalGross()
                    ? OrderRefundStatus::REFUNDED->name
                    : OrderRefundStatus::PARTIALLY_REFUNDED->name;

                $this->orderRepository->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::REFUND_STATUS => $refundStatus,
                ]);

                $this->eventStatisticsRefundService->updateForRefund(
                    $order,
                    MoneyValue::fromFloat($refundedAmount, $order->getCurrency()),
                );
            }

            $completed = $this->swishRefundsRepository->updateFromArray($fresh->getId(), [
                SwishRefundDomainObjectAbstract::STATUS => SwishRefundStatus::PAID->value,
                SwishRefundDomainObjectAbstract::PAYMENT_REFERENCE => $remote->paymentReference,
                SwishRefundDomainObjectAbstract::DATE_PAID => $remote->datePaid ?? now()->toDateTimeString(),
                SwishRefundDomainObjectAbstract::CALLBACK_PAYLOAD => $remote->raw ?: $fresh->getCallbackPayload(),
                SwishRefundDomainObjectAbstract::ERROR_CODE => null,
                SwishRefundDomainObjectAbstract::ERROR_MESSAGE => null,
            ]);

            $this->logger->info('Swish refund completed', [
                'swish_refund_id' => $completed->getId(),
                'instruction_uuid' => $completed->getInstructionUuid(),
                'order_id' => $order->getId(),
                'amount' => $completed->getAmount(),
                'status' => SwishRefundStatus::PAID->value,
                'payment_reference' => $remote->paymentReference,
            ]);

            return ['refund' => $completed, 'applied' => true, 'order_id' => $order->getId()];
        });

        if ($result['applied']) {
            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_REFUNDED,
                    orderId: $result['order_id'],
                ),
            );
        }

        return $result['refund'];
    }

    /**
     * @throws Throwable
     */
    public function fail(SwishRefundDomainObject $refund, SwishRefundDTO $remote): SwishRefundDomainObject
    {
        return $this->databaseManager->transaction(function () use ($refund, $remote) {
            /** @var SwishRefundDomainObject $fresh */
            $fresh = $this->swishRefundsRepository->findById($refund->getId());

            if ($fresh->isTerminal()) {
                return $fresh;
            }

            $failed = $this->swishRefundsRepository->updateFromArray($fresh->getId(), [
                SwishRefundDomainObjectAbstract::STATUS => SwishRefundStatus::ERROR->value,
                SwishRefundDomainObjectAbstract::ERROR_CODE => $remote->errorCode,
                SwishRefundDomainObjectAbstract::ERROR_MESSAGE => $remote->errorMessage !== null ? Str::limit($remote->errorMessage, 500) : null,
                SwishRefundDomainObjectAbstract::CALLBACK_PAYLOAD => $remote->raw ?: $fresh->getCallbackPayload(),
            ]);

            $this->orderRepository->updateWhere(
                attributes: [OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_FAILED->name],
                where: [
                    OrderDomainObjectAbstract::ID => $fresh->getOrderId(),
                    OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
                ],
            );

            $this->logger->error('Swish refund failed', [
                'swish_refund_id' => $failed->getId(),
                'instruction_uuid' => $failed->getInstructionUuid(),
                'order_id' => $failed->getOrderId(),
                'status' => SwishRefundStatus::ERROR->value,
                'error_code' => $remote->errorCode,
                'error_message' => $remote->errorMessage,
            ]);

            return $failed;
        });
    }
}
