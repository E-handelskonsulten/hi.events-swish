<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use HiEvents\Mail\Swish\SwishRefundFailedMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SwishAlertMailTest extends SwishFeatureTestCase
{
    public function test_failed_refund_alerts_the_configured_address(): void
    {
        config(['app.alerts_email' => 'alerts@example.test']);
        Mail::fake();

        $this->failARefund();

        Mail::assertQueued(SwishRefundFailedMail::class, static function (SwishRefundFailedMail $mail): bool {
            return $mail->hasTo('alerts@example.test');
        });
    }

    public function test_failed_refund_sends_nothing_when_no_alert_address_is_configured(): void
    {
        config(['app.alerts_email' => null]);
        Mail::fake();

        $this->failARefund();

        Mail::assertNotQueued(SwishRefundFailedMail::class);
    }

    private function failARefund(): void
    {
        $orderId = $this->createPaidSwishOrder();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/X']);
        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $refund = DB::table('swish_refunds')->where('order_id', $orderId)->first();
        $error = [
            'id' => $refund->instruction_uuid,
            'paymentReference' => 'REFUNDREF'.substr($refund->instruction_uuid, 0, 6),
            'payerPaymentReference' => (string) $orderId,
            'originalPaymentReference' => 'PAYREF123',
            'callbackUrl' => 'https://callbacks.test/api/public/webhooks/swish/refunds',
            'payerAlias' => self::PAYEE_ALIAS,
            'payeeAlias' => '46701234567',
            'amount' => '25.00',
            'currency' => 'SEK',
            'message' => 'Refund',
            'status' => 'ERROR',
            'dateCreated' => now()->subMinute()->toIso8601String(),
            'datePaid' => null,
            'errorCode' => 'RF07',
            'errorMessage' => 'Transaction declined',
        ];
        $this->queueSwishJson($error);

        $this->postJson('/public/webhooks/swish/refunds', $error)->assertOk();
    }
}
