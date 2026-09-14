<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\DTO\CreateSwishPaymentDTO;
use HiEvents\Services\Domain\Payment\Swish\SwishPayerAliasNormalizer;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentFailureService;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentRequestService;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentStatusReconciliationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class CreateSwishPaymentHandler
{
    private const IN_FLIGHT_GRACE_SECONDS = 60;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly CheckoutSessionManagementService $sessionManagementService,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly SwishPaymentRequestService $swishPaymentRequestService,
        private readonly SwishPaymentStatusReconciliationService $reconciliationService,
        private readonly SwishPaymentFailureService $failureService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws ResourceConflictException
     * @throws UnauthorizedException
     * @throws SwishConfigurationException
     * @throws SwishApiException
     * @throws Throwable
     */
    public function handle(CreateSwishPaymentDTO $dto): SwishPaymentDomainObject
    {
        $order = $this->loadOrder($dto);

        $this->assertOrderIsPayable($order);
        $this->assertSwishEnabledForEvent($order->getEventId());

        $connection = $this->swishConfigurationService->resolveForOrganizer($order->getEvent()->getOrganizerId());
        $payerAlias = $this->resolvePayerAlias($dto);

        $existing = $this->swishPaymentsRepository->findFirstWhere([
            SwishPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::CREATED->value,
        ]);

        if ($existing !== null && ! $this->canReuse($existing, $dto->flow, $payerAlias)) {
            $this->supersede($existing);
        }

        [$payment, $isNew] = $this->databaseManager->transaction(function () use ($order, $dto, $payerAlias, $connection) {
            $this->databaseManager->statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['swish:'.$order->getShortId()]);

            $pending = $this->swishPaymentsRepository->findFirstWhere([
                SwishPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
                SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::CREATED->value,
            ]);

            if ($pending !== null) {
                return [$pending, false];
            }

            return [
                $this->swishPaymentRequestService->createPendingRecord($order, $dto->flow, $payerAlias, $connection),
                true,
            ];
        });

        if (! $isNew) {
            $this->logger->info('Reusing pending Swish payment request for order', [
                'order_id' => $order->getId(),
                'swish_payment_id' => $payment->getId(),
                'instruction_uuid' => $payment->getInstructionUuid(),
                'status' => $payment->getStatus(),
            ]);

            return $payment->setOrder($order);
        }

        return $this->swishPaymentRequestService
            ->submit($payment, $order, $order->getEvent(), $connection)
            ->setOrder($order);
    }

    /**
     * @throws ResourceNotFoundException
     * @throws UnauthorizedException
     */
    private function loadOrder(CreateSwishPaymentDTO $dto): OrderDomainObject
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                OrderDomainObjectAbstract::EVENT_ID => $dto->eventId,
                OrderDomainObjectAbstract::SHORT_ID => $dto->orderShortId,
            ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->getSessionId() === null || ! $this->sessionManagementService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please restart your order.'));
        }

        return $order;
    }

    /**
     * @throws ResourceConflictException
     */
    private function assertOrderIsPayable(OrderDomainObject $order): void
    {
        if (! $order->isOrderReserved() || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('This order has expired or is no longer awaiting payment.'));
        }

        if (! $order->isPaymentRequired()) {
            throw new ResourceConflictException(__('This order does not require payment.'));
        }

        if (! in_array($order->getPaymentStatus(), [OrderPaymentStatus::AWAITING_PAYMENT->name, OrderPaymentStatus::PAYMENT_FAILED->name], true)) {
            throw new ResourceConflictException(__('Please complete your order details before paying.'));
        }

        if (strtoupper((string) $order->getCurrency()) !== SwishPaymentRequestService::CURRENCY) {
            throw new ResourceConflictException(__('Swish payments are only available for orders in SEK.'));
        }
    }

    /**
     * @throws UnauthorizedException
     */
    private function assertSwishEnabledForEvent(int $eventId): void
    {
        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere([
            EventSettingDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $providers = $settings?->getPaymentProviders() ?? [];

        if (! in_array(PaymentProviders::SWISH->value, is_array($providers) ? $providers : [], true)) {
            throw new UnauthorizedException(__('Swish payments are not enabled for this event.'));
        }
    }

    /**
     * @throws ResourceConflictException
     */
    private function resolvePayerAlias(CreateSwishPaymentDTO $dto): ?string
    {
        if ($dto->flow !== SwishCheckoutFlow::ECOMMERCE) {
            return null;
        }

        $normalized = $dto->payerAlias !== null ? SwishPayerAliasNormalizer::normalize($dto->payerAlias) : null;

        if ($normalized === null) {
            throw new ResourceConflictException(__('Please enter a valid Swedish mobile number.'));
        }

        return $normalized;
    }

    private function canReuse(SwishPaymentDomainObject $existing, SwishCheckoutFlow $flow, ?string $payerAlias): bool
    {
        if ($existing->getFlow() !== $flow->value) {
            return false;
        }

        if ($flow === SwishCheckoutFlow::ECOMMERCE && $existing->getPayerAlias() !== $payerAlias) {
            return false;
        }

        $submitted = $existing->getLocationUrl() !== null || $existing->getPaymentRequestToken() !== null;
        $recentlyCreated = Carbon::parse($existing->getCreatedAt())->diffInSeconds(now()) < self::IN_FLIGHT_GRACE_SECONDS;

        return $submitted || $recentlyCreated;
    }

    /**
     * @throws ResourceConflictException
     * @throws Throwable
     */
    private function supersede(SwishPaymentDomainObject $existing): void
    {
        $wasSubmitted = $existing->getLocationUrl() !== null || $existing->getPaymentRequestToken() !== null;

        $result = $wasSubmitted
            ? $this->reconciliationService->cancel($existing)
            : $this->failureService->markCancelled($existing);

        if (in_array($result->getStatusEnum(), [SwishPaymentStatus::PAID, SwishPaymentStatus::PAID_FLAGGED], true)) {
            throw new ResourceConflictException(__('This order has already been paid.'));
        }

        $this->logger->info('Superseded pending Swish payment request', [
            'order_id' => $existing->getOrderId(),
            'swish_payment_id' => $existing->getId(),
            'instruction_uuid' => $existing->getInstructionUuid(),
            'status' => $result->getStatus(),
        ]);
    }
}
