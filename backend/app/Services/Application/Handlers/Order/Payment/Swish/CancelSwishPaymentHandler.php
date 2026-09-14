<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

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
use Throwable;

class CancelSwishPaymentHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly CheckoutSessionManagementService $sessionManagementService,
        private readonly SwishPaymentStatusReconciliationService $reconciliationService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws UnauthorizedException
     * @throws SwishApiException
     * @throws SwishConfigurationException
     * @throws Throwable
     */
    public function handle(int $eventId, string $orderShortId): SwishPaymentDomainObject
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::SHORT_ID => $orderShortId,
        ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->getSessionId() === null || ! $this->sessionManagementService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please restart your order.'));
        }

        $payment = $this->swishPaymentsRepository->findLatestForOrder($order->getId());

        if ($payment === null) {
            throw new ResourceNotFoundException(__('No Swish payment found for this order.'));
        }

        $payment = $this->reconciliationService->cancel($payment);

        return $payment->setOrder($this->orderRepository->findById($order->getId()));
    }
}
