<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Mail\Swish\SwishPaymentNeedsReviewMail;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentCompletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

class SwishPaymentCallbackTest extends SwishFeatureTestCase
{
    public function test_paid_callback_completes_the_order_after_verifying_status_with_swish(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $paid = $this->swishPaymentPayload($instructionUuid, $orderId, 'PAID');
        $this->queueSwishJson($paid);

        $this->postPaymentCallback($paid)->assertOk();

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COMPLETED->name, $order->status);
        $this->assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $order->payment_status);
        $this->assertSame(PaymentProviders::SWISH->value, $order->payment_provider);

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::PAID->value, $payment->status);
        $this->assertSame($paid['paymentReference'], $payment->payment_reference);
        $this->assertSame('46701234567', $payment->payer_alias);
        $this->assertNotNull($payment->date_paid);
        $this->assertNotNull($payment->callback_payload);

        $this->assertSame(
            AttendeeStatus::ACTIVE->name,
            DB::table('attendees')->where('order_id', $orderId)->value('status'),
        );
        $this->assertSame(1, (int) DB::table('product_prices')->where('id', $this->productPriceId)->value('quantity_sold'));

        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    public function test_callback_status_is_never_trusted_without_confirmation_from_swish(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'CREATED', ['paymentReference' => null, 'datePaid' => null]));

        $this->postPaymentCallback($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID'))->assertOk();

        $this->assertSame(OrderStatus::RESERVED->name, $this->orderRow($orderId)->status);
        $this->assertSame(SwishPaymentStatus::CREATED->value, $this->swishPaymentRow($paymentId)->status);
        Event::assertNotDispatched(OrderStatusChangedEvent::class);
    }

    public function test_duplicate_paid_callback_is_ignored_without_contacting_swish(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $paid = $this->swishPaymentPayload($instructionUuid, $orderId, 'PAID');
        $this->queueSwishJson($paid);

        $this->postPaymentCallback($paid)->assertOk();
        $this->postPaymentCallback($paid)->assertOk();

        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);
        $this->assertSame(SwishPaymentStatus::PAID->value, $this->swishPaymentRow($paymentId)->status);
        $this->assertSame(1, (int) DB::table('product_prices')->where('id', $this->productPriceId)->value('quantity_sold'));
        Event::assertDispatchedTimes(OrderStatusChangedEvent::class, 1);
    }

    public function test_callback_for_unknown_payment_request_is_acknowledged_and_ignored(): void
    {
        $orderId = $this->createReservedOrder();

        $this->postPaymentCallback($this->swishPaymentPayload('0F9A6B1E2C3D4E5F6A7B8C9D0E1F2A3B', $orderId, 'PAID'))->assertOk();

        $this->assertSame(OrderStatus::RESERVED->name, $this->orderRow($orderId)->status);
    }

    public function test_callback_without_id_is_rejected(): void
    {
        $this->postPaymentCallback(['status' => 'PAID'])->assertStatus(400);
    }

    public function test_paid_amount_mismatch_flags_the_payment_for_review_instead_of_completing(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);
        Mail::fake();

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $this->queueSwishJson($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID', ['amount' => '10.00']));

        $this->postPaymentCallback($this->swishPaymentPayload($instructionUuid, $orderId, 'PAID'))->assertOk();

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::PAID_FLAGGED->value, $payment->status);
        $this->assertSame(SwishPaymentCompletionService::FLAG_AMOUNT_MISMATCH, $payment->flag_reason);

        $this->assertSame(OrderStatus::RESERVED->name, $this->orderRow($orderId)->status);
        Event::assertNotDispatched(OrderStatusChangedEvent::class);
        Mail::assertQueued(SwishPaymentNeedsReviewMail::class);
    }

    public function test_declined_payment_marks_the_order_payment_as_failed_and_keeps_it_payable(): void
    {
        Event::fake([OrderStatusChangedEvent::class]);

        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $declined = $this->swishPaymentPayload($instructionUuid, $orderId, 'DECLINED', ['paymentReference' => null, 'datePaid' => null]);
        $this->queueSwishJson($declined);

        $this->postPaymentCallback($declined)->assertOk();

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::DECLINED->value, $payment->status);

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::RESERVED->name, $order->status);
        $this->assertSame(OrderPaymentStatus::PAYMENT_FAILED->name, $order->payment_status);
        $this->assertSame(
            AttendeeStatus::AWAITING_PAYMENT->name,
            DB::table('attendees')->where('order_id', $orderId)->value('status'),
        );
        Event::assertNotDispatched(OrderStatusChangedEvent::class);
    }

    public function test_error_from_swish_records_the_error_code(): void
    {
        $orderId = $this->createReservedOrder();
        [$paymentId, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $error = $this->swishPaymentPayload($instructionUuid, $orderId, 'ERROR', [
            'paymentReference' => null,
            'datePaid' => null,
            'errorCode' => 'RF07',
            'errorMessage' => 'Transaction declined',
        ]);
        $this->queueSwishJson($error);

        $this->postPaymentCallback($error)->assertOk();

        $payment = $this->swishPaymentRow($paymentId);
        $this->assertSame(SwishPaymentStatus::ERROR->value, $payment->status);
        $this->assertSame('RF07', $payment->error_code);
        $this->assertSame(OrderPaymentStatus::PAYMENT_FAILED->name, $this->orderRow($orderId)->payment_status);
    }
}
