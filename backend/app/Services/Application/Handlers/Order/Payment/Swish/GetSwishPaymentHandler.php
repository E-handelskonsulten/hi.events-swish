<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentStatusReconciliationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Psr\Log\LoggerInterface;
use Throwable;

class GetSwishPaymentHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly CheckoutSessionManagementService $sessionManagementService,
        private readonly SwishPaymentStatusReconciliationService $reconciliationService,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws UnauthorizedException
     * @throws Throwable
     */
    public function handle(int $eventId, string $orderShortId): SwishPaymentDomainObject
    {
        $order = $this->loadOrder($eventId, $orderShortId);

        $payment = $this->swishPaymentsRepository->findLatestForOrder($order->getId());

        if ($payment === null) {
            throw new ResourceNotFoundException(__('No Swish payment found for this order.'));
        }

        if (! $payment->isTerminal() && $this->shouldPollSwish($payment)) {
            try {
                $payment = $this->reconciliationService->reconcile($payment);
            } catch (SwishApiException|SwishConfigurationException $exception) {
                $this->logger->warning('Swish status poll failed, returning local state', [
                    'swish_payment_id' => $payment->getId(),
                    'instruction_uuid' => $payment->getInstructionUuid(),
                    'order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $payment->setOrder($this->orderRepository->findById($order->getId()));
    }

    /**
     * @throws ResourceNotFoundException
     * @throws UnauthorizedException
     */
    private function loadOrder(int $eventId, string $orderShortId): OrderDomainObject
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::SHORT_ID => $orderShortId,
        ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->isOrderReserved()
            && ($order->getSessionId() === null || ! $this->sessionManagementService->verifySession($order->getSessionId()))) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please restart your order.'));
        }

        return $order;
    }

    private function shouldPollSwish(SwishPaymentDomainObject $payment): bool
    {
        if ($payment->getLastPolledAt() === null) {
            return true;
        }

        return Carbon::parse($payment->getLastPolledAt())->diffInSeconds(now())
            >= $this->swishConfigurationService->minimumStatusPollIntervalSeconds();
    }
}
