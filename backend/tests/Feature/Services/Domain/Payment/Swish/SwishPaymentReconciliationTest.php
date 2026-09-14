<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Order\Swish\ReconcilePendingSwishPaymentsJob;
use HiEvents\Mail\Swish\SwishPaymentNeedsReviewMail;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\CreateSwishPaymentHandler;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\DTO\CreateSwishPaymentDTO;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentCompletionService;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentStatusReconciliationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Psr\Log\LoggerInterface;

class SwishPaymentReconciliationTest extends SwishFeatureTestCase
{
    public function test_poller_completes_a_payment_whose_callback_was_lost(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID'));

        $this->app->make(ReconcilePendingSwishPaymentsJob::class)->handle(
            $this->app->make(SwishPaymentsRepositoryInterface::class),
            $this->app->make(SwishPaymentStatusReconciliationService::class),
            $this->app->make(LoggerInterface::class),
        );

        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::PAID->value, $payment->status);
        $this->assertSame(1, (int) $payment->poll_attempts);
        $this->assertNotNull($payment->last_polled_at);
        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    public function test_poller_cancels_a_pending_request_once_the_reservation_has_expired(): void
    {
        $orderId = $this->createReservedOrder(reservedUntil: now()->subMinutes(5)->toDateTimeString());
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'CREATED', ['paymentReference' => null, 'datePaid' => null]));
        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'CANCELLED', ['paymentReference' => null, 'datePaid' => null]));

        $this->runPoller();

        $this->assertSame(SwishPaymentStatus::EXPIRED->value, $this->swishPaymentRow($paymentId)->status);
        $this->assertSame(OrderStatus::RESERVED->name, $this->orderRow($orderId)->status);
    }

    public function test_late_paid_after_reservation_expiry_completes_when_inventory_is_still_available(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder(reservedUntil: now()->subMinutes(5)->toDateTimeString());
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID'));

        $this->runPoller();

        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);
        $this->assertSame(SwishPaymentStatus::PAID->value, $this->swishPaymentRow($paymentId)->status);
        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    public function test_late_paid_after_reservation_expiry_is_flagged_when_inventory_is_gone(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);
        Mail::fake();

        DB::table('product_prices')->where('id', $this->productPriceId)->update([
            'initial_quantity_available' => 1,
            'quantity_sold' => 1,
        ]);

        $orderId = $this->createReservedOrder(reservedUntil: now()->subMinutes(5)->toDateTimeString());
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID'));

        $this->runPoller();

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::PAID_FLAGGED->value, $payment->status);
        $this->assertSame(SwishPaymentCompletionService::FLAG_RESERVATION_EXPIRED_NO_INVENTORY, $payment->flag_reason);
        $this->assertSame(OrderStatus::RESERVED->name, $this->orderRow($orderId)->status);
        $this->assertSame(1, (int) DB::table('product_prices')->where('id', $this->productPriceId)->value('quantity_sold'));

        Event::assertNotDispatched(OrderStatusChangedEvent::class);
        Mail::assertQueued(SwishPaymentNeedsReviewMail::class);
    }

    public function test_double_submit_reuses_the_pending_payment_request(): void
    {
        $orderId = $this->createReservedOrder();
        $orderShortId = DB::table('orders')->where('id', $orderId)->value('short_id');

        $session = Mockery::mock(CheckoutSessionManagementService::class);
        $session->shouldReceive('verifySession')->andReturnTrue();
        $this->app->instance(CheckoutSessionManagementService::class, $session);

        $this->queueSwishResponse(201, [
            'Location' => 'https://swish.test/swish-cpcapi/api/v1/paymentrequests/ABC',
        ]);

        $handler = $this->app->make(CreateSwishPaymentHandler::class);
        $dto = new CreateSwishPaymentDTO(
            eventId: $this->eventId,
            orderShortId: $orderShortId,
            flow: SwishCheckoutFlow::ECOMMERCE,
            payerAlias: '070 123 45 67',
        );

        $first = $handler->handle($dto);
        $second = $handler->handle($dto);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('46701234567', $first->getPayerAlias());
        $this->assertSame(1, DB::table('swish_payments')->where('order_id', $orderId)->count());
        $this->assertSame(SwishPaymentStatus::CREATED->value, $this->swishPaymentRow($first->getId())->status);
    }

    private function runPoller(): void
    {
        $this->app->make(ReconcilePendingSwishPaymentsJob::class)->handle(
            $this->app->make(SwishPaymentsRepositoryInterface::class),
            $this->app->make(SwishPaymentStatusReconciliationService::class),
            $this->app->make(LoggerInterface::class),
        );
    }
}
