<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Sms;

use GuzzleHttp\Promise\PromiseInterface;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Jobs\Sms\SendOrderSmsJob;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class OrderSmsDeliveryTest extends SwishFeatureTestCase
{
    private const ELKS_URL = 'https://api.46elks.com/a1/sms';

    private MockInterface $orderEmails;

    private PromiseInterface $elksResponse;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sms.enabled', true);
        Config::set('sms.dry_run', false);
        Config::set('sms.default_sender', 'Biljettera');
        Config::set('sms.elks.username', 'u-test');
        Config::set('sms.elks.password', 'p-test');
        Config::set('app.frontend_url', 'https://demo.test');

        $this->orderEmails = Mockery::mock(SendOrderDetailsService::class);
        $this->orderEmails->shouldReceive('sendOrderSummaryAndTicketEmails')->byDefault();
        $this->app->instance(SendOrderDetailsService::class, $this->orderEmails);

        $this->elksResponse = Http::response(['id' => 's-123', 'status' => 'created', 'parts' => 2]);
        Http::fake([self::ELKS_URL => fn () => $this->elksResponse]);
    }

    public function test_ticket_sms_is_sent_when_a_swish_order_completes(): void
    {
        $this->enableSms();

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertSent(function (Request $request) use ($orderId) {
            $shortId = DB::table('orders')->where('id', $orderId)->value('short_id');

            return $request->url() === self::ELKS_URL
                && $request['to'] === '+46701234567'
                && $request['from'] === 'Biljettera'
                && $request['message'] === "Hi Test! Your ticket for Swish Test Event: https://demo.test/checkout/{$this->eventId}/{$shortId}/summary"
                && ! isset($request['dryrun']);
        });

        $row = DB::table('sms_messages')->where('order_id', $orderId)->first();
        $this->assertSame(SmsMessageStatus::SENT->name, $row->status);
        $this->assertSame(SmsMessageType::TICKET->name, $row->type);
        $this->assertSame($this->organizerId, (int) $row->organizer_id);
        $this->assertSame($this->eventId, (int) $row->event_id);
        $this->assertSame('+46701234567', $row->recipient);
        $this->assertSame('Biljettera', $row->sender);
        $this->assertSame(2, (int) $row->parts);
        $this->assertSame('s-123', $row->provider_message_id);
        $this->assertNotNull($row->sent_at);
    }

    public function test_custom_sender_name_is_used(): void
    {
        $this->enableSms(sender: 'Klubben');

        $this->completeOrderThroughSwishCallback();

        Http::assertSent(fn (Request $request) => $request['from'] === 'Klubben');
    }

    public function test_no_sms_when_the_organizer_has_not_enabled_delivery(): void
    {
        $this->enableSms(enabled: false);

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('sms_messages')->where('order_id', $orderId)->count());
        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);
    }

    public function test_no_sms_when_the_organizer_never_saved_settings(): void
    {
        $this->completeOrderThroughSwishCallback();

        Http::assertNothingSent();
    }

    public function test_delivery_failure_leaves_the_order_and_emails_untouched(): void
    {
        $this->enableSms();
        $this->elksResponse = Http::response('Invalid from', 403);

        $orderId = $this->completeOrderThroughSwishCallback();

        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);
        $this->assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $this->orderRow($orderId)->payment_status);
        $this->orderEmails->shouldHaveReceived('sendOrderSummaryAndTicketEmails')->once();

        $row = DB::table('sms_messages')->where('order_id', $orderId)->first();
        $this->assertSame(SmsMessageStatus::FAILED->name, $row->status);
        $this->assertStringContainsString('HTTP 403', $row->error_message);
        $this->assertNull($row->sent_at);
    }

    public function test_sms_is_skipped_when_no_mobile_number_is_known(): void
    {
        $this->enableSms();
        $orderId = $this->createCompletedOrderWithoutSwish();

        dispatch(new SendOrderSmsJob($orderId, SmsMessageType::TICKET));

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('sms_messages')->where('order_id', $orderId)->count());
    }

    public function test_checkout_phone_is_used_when_there_is_no_swish_payment(): void
    {
        $this->enableSms();
        $orderId = $this->createCompletedOrderWithoutSwish(phone: '46709876543');

        dispatch(new SendOrderSmsJob($orderId, SmsMessageType::TICKET));

        Http::assertSent(fn (Request $request) => $request['to'] === '+46709876543');
    }

    public function test_completion_sms_is_never_sent_twice_for_the_same_order(): void
    {
        $this->enableSms();
        $orderId = $this->completeOrderThroughSwishCallback();

        dispatch(new SendOrderSmsJob($orderId, SmsMessageType::TICKET));

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('sms_messages')->where('order_id', $orderId)->count());
    }

    public function test_refund_notice_is_sent_when_an_order_is_fully_refunded(): void
    {
        $this->enableSms();
        $orderId = $this->createPaidSwishOrder();
        $this->markRefunded($orderId, self::ORDER_TOTAL);

        event(new OrderEvent(DomainEventType::ORDER_REFUNDED, $orderId));

        Http::assertSent(fn (Request $request) => $request['to'] === '+46701234567'
            && $request['message'] === 'Hi Test! Your order for Swish Test Event has been refunded and the ticket is no longer valid.');

        $row = DB::table('sms_messages')->where('order_id', $orderId)->first();
        $this->assertSame(SmsMessageType::REFUND_NOTICE->name, $row->type);
        $this->assertSame(SmsMessageStatus::SENT->name, $row->status);
    }

    public function test_refund_notice_respects_the_organizer_toggle(): void
    {
        $this->enableSms(enabled: false);
        $orderId = $this->createPaidSwishOrder();
        $this->markRefunded($orderId, self::ORDER_TOTAL);

        event(new OrderEvent(DomainEventType::ORDER_REFUNDED, $orderId));

        Http::assertNothingSent();
    }

    public function test_partial_refunds_do_not_trigger_a_refund_notice(): void
    {
        $this->enableSms();
        $orderId = $this->createPaidSwishOrder();
        $this->markRefunded($orderId, 10.00);

        event(new OrderEvent(DomainEventType::ORDER_REFUNDED, $orderId));

        Http::assertNothingSent();
    }

    public function test_sent_messages_are_counted_per_organizer_for_a_period(): void
    {
        $this->enableSms();
        $first = $this->completeOrderThroughSwishCallback();
        $second = $this->createPaidSwishOrder();
        $this->markRefunded($second, self::ORDER_TOTAL);
        event(new OrderEvent(DomainEventType::ORDER_REFUNDED, $second));

        DB::table('sms_messages')->where('order_id', $first)->update(['sent_at' => now()->subMonths(2)]);

        $counts = app(SmsMessagesRepositoryInterface::class)
            ->countSentPerOrganizerBetween(now()->startOfMonth(), now()->addMonth()->startOfMonth());

        $this->assertSame(1, $counts[$this->organizerId]);
    }

    private function enableSms(bool $enabled = true, ?string $sender = null): void
    {
        DB::table('organizer_billing_settings')->insert([
            'organizer_id' => $this->organizerId,
            'sms_enabled' => $enabled,
            'sms_sender_name' => $sender,
            'platform_fee_per_ticket' => 6.00,
            'sms_fee_per_message' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function completeOrderThroughSwishCallback(): int
    {
        $orderId = $this->createReservedOrder();
        [, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $paid = $this->swishPaymentPayload($instructionUuid, $orderId, 'PAID');
        $this->queueSwishJson($paid);
        $this->postPaymentCallback($paid)->assertOk();

        return $orderId;
    }

    private function createCompletedOrderWithoutSwish(?string $phone = null): int
    {
        $orderId = $this->createReservedOrder();

        DB::table('orders')->where('id', $orderId)->update([
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::NO_PAYMENT_REQUIRED->name,
            'phone' => $phone,
        ]);

        return $orderId;
    }

    private function markRefunded(int $orderId, float $amount): void
    {
        DB::table('orders')->where('id', $orderId)->update([
            'total_refunded' => $amount,
            'refund_status' => $amount >= self::ORDER_TOTAL
                ? OrderRefundStatus::REFUNDED->name
                : OrderRefundStatus::PARTIALLY_REFUNDED->name,
        ]);
    }
}
