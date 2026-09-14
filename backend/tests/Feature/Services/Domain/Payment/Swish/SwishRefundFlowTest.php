<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use Illuminate\Support\Facades\DB;

class SwishRefundFlowTest extends SwishFeatureTestCase
{
    public function test_refund_is_created_at_swish_and_completed_by_the_refund_callback(): void
    {
        $orderId = $this->createPaidSwishOrder();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/X']);

        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $refund = DB::table('swish_refunds')->where('order_id', $orderId)->first();
        $this->assertNotNull($refund);
        $this->assertSame(SwishRefundStatus::CREATED->value, $refund->status);
        $this->assertSame('PAYREF123', $refund->original_payment_reference);
        $this->assertSame(self::PAYEE_ALIAS, $refund->payer_alias);
        $this->assertSame('46701234567', $refund->payee_alias);
        $this->assertSame(OrderRefundStatus::REFUND_PENDING->name, $this->orderRow($orderId)->refund_status);

        $paid = $this->refundPayload($refund->instruction_uuid, $orderId, 'PAID');
        $this->queueSwishJson($paid);

        $this->postJson('/public/webhooks/swish/refunds', $paid)->assertOk();

        $this->assertSame(SwishRefundStatus::PAID->value, DB::table('swish_refunds')->where('id', $refund->id)->value('status'));

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderRefundStatus::REFUNDED->name, $order->refund_status);
        $this->assertEqualsWithDelta(25.0, (float) $order->total_refunded, 0.001);

        $orderRefund = DB::table('order_refunds')->where('order_id', $orderId)->first();
        $this->assertNotNull($orderRefund);
        $this->assertSame(PaymentProviders::SWISH->value, $orderRefund->payment_provider);
        $this->assertSame($refund->instruction_uuid, $orderRefund->refund_id);
        $this->assertEqualsWithDelta(25.0, (float) $orderRefund->amount, 0.001);
    }

    public function test_duplicate_refund_callback_does_not_record_the_refund_twice(): void
    {
        $orderId = $this->createPaidSwishOrder();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/X']);
        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 10,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $refund = DB::table('swish_refunds')->where('order_id', $orderId)->first();
        $paid = $this->refundPayload($refund->instruction_uuid, $orderId, 'PAID', ['amount' => '10.00']);
        $this->queueSwishJson($paid);

        $this->postJson('/public/webhooks/swish/refunds', $paid)->assertOk();
        $this->postJson('/public/webhooks/swish/refunds', $paid)->assertOk();

        $this->assertSame(1, DB::table('order_refunds')->where('order_id', $orderId)->count());

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderRefundStatus::PARTIALLY_REFUNDED->name, $order->refund_status);
        $this->assertEqualsWithDelta(10.0, (float) $order->total_refunded, 0.001);
    }

    public function test_refund_error_from_swish_marks_the_order_refund_as_failed(): void
    {
        $orderId = $this->createPaidSwishOrder();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/X']);
        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $refund = DB::table('swish_refunds')->where('order_id', $orderId)->first();
        $error = $this->refundPayload($refund->instruction_uuid, $orderId, 'ERROR', [
            'datePaid' => null,
            'errorCode' => 'RF07',
            'errorMessage' => 'Transaction declined',
        ]);
        $this->queueSwishJson($error);

        $this->postJson('/public/webhooks/swish/refunds', $error)->assertOk();

        $storedRefund = DB::table('swish_refunds')->where('id', $refund->id)->first();
        $this->assertSame(SwishRefundStatus::ERROR->value, $storedRefund->status);
        $this->assertSame('RF07', $storedRefund->error_code);
        $this->assertSame(OrderRefundStatus::REFUND_FAILED->name, $this->orderRow($orderId)->refund_status);
        $this->assertSame(0, DB::table('order_refunds')->where('order_id', $orderId)->count());
    }

    public function test_refund_is_rejected_when_swish_declines_the_request(): void
    {
        $orderId = $this->createPaidSwishOrder();

        $this->queueSwishResponse(422, ['Content-Type' => 'application/json'], json_encode([
            ['errorCode' => 'PA02', 'errorMessage' => 'Amount value is missing or not a valid number.'],
        ], JSON_THROW_ON_ERROR));

        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(SwishRefundStatus::ERROR->value, DB::table('swish_refunds')->where('order_id', $orderId)->value('status'));
        $this->assertNull($this->orderRow($orderId)->refund_status);
    }

    public function test_refund_is_rejected_when_the_order_has_no_paid_swish_payment(): void
    {
        $orderId = $this->createReservedOrder();
        DB::table('orders')->where('id', $orderId)->update([
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'payment_provider' => PaymentProviders::SWISH->value,
        ]);

        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    private function refundPayload(string $instructionUuid, int $orderId, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => $instructionUuid,
            'paymentReference' => 'REFUNDREF'.substr($instructionUuid, 0, 6),
            'payerPaymentReference' => (string) $orderId,
            'originalPaymentReference' => 'PAYREF123',
            'callbackUrl' => 'https://callbacks.test/api/public/webhooks/swish/refunds',
            'payerAlias' => self::PAYEE_ALIAS,
            'payeeAlias' => '46701234567',
            'amount' => '25.00',
            'currency' => 'SEK',
            'message' => 'Refund',
            'status' => $status,
            'dateCreated' => now()->subMinute()->toIso8601String(),
            'datePaid' => now()->toIso8601String(),
            'errorCode' => null,
            'errorMessage' => null,
        ], $overrides);
    }
}
