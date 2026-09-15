<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Billing;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\BillingSummaryOutcome;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Mail\Billing\MonthlyBillingSummaryMail;
use HiEvents\Models\Account;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Billing\DTO\OrganizerBillingLineDTO;
use HiEvents\Services\Domain\Billing\MonthlyBillingSummaryDispatchService;
use HiEvents\Services\Domain\Billing\MonthlyBillingSummaryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonthlyBillingSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private const AUGUST = '2026-08-01';

    private int $accountId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('billing.summary_email', 'billing@biljettera.test');
        Config::set('billing.default_platform_fee_per_ticket', 6.00);
        Config::set('billing.default_sms_fee_per_message', 0.50);
        Config::set('billing.timezone', 'Europe/Stockholm');
        Config::set('billing.currency', 'SEK');

        $this->accountId = Account::factory()->create()->id;
        $this->userId = User::factory()->create()->id;

        Mail::fake();
    }

    public function test_only_orders_inside_the_stockholm_calendar_month_are_counted(): void
    {
        $organizerId = $this->createOrganizer('Boundary Org');
        $eventId = $this->createEvent($organizerId);

        $this->createSoldOrder($eventId, tickets: 1, createdAtUtc: '2026-07-31 21:59:59');
        $this->createSoldOrder($eventId, tickets: 2, createdAtUtc: '2026-07-31 22:00:00');
        $this->createSoldOrder($eventId, tickets: 3, createdAtUtc: '2026-08-31 21:59:59');
        $this->createSoldOrder($eventId, tickets: 4, createdAtUtc: '2026-08-31 22:00:00');

        $line = $this->lineFor($organizerId);

        $this->assertSame(5, $line->soldTickets);
        $this->assertSame(2, $line->soldOrders);
        $this->assertSame(30.0, $line->platformFeeTotal);
        $this->assertSame(500.0, $line->grossSales);
    }

    public function test_refunds_are_reported_but_do_not_reduce_the_fee(): void
    {
        $organizerId = $this->createOrganizer('Refund Org');
        $eventId = $this->createEvent($organizerId);

        $this->createSoldOrder($eventId, tickets: 2, createdAtUtc: '2026-08-10 10:00:00');
        $this->createSoldOrder($eventId, tickets: 3, createdAtUtc: '2026-08-11 10:00:00', refunded: true);

        $line = $this->lineFor($organizerId);

        $this->assertSame(5, $line->soldTickets);
        $this->assertSame(3, $line->refundedTickets);
        $this->assertSame(30.0, $line->platformFeeTotal);
        $this->assertSame(30.0, $line->total);
    }

    public function test_unpaid_and_free_orders_are_not_billable(): void
    {
        $organizerId = $this->createOrganizer('Free Org');
        $eventId = $this->createEvent($organizerId);

        $this->createSoldOrder($eventId, tickets: 2, createdAtUtc: '2026-08-10 10:00:00', paymentStatus: OrderPaymentStatus::NO_PAYMENT_REQUIRED->name);
        $this->createSoldOrder($eventId, tickets: 2, createdAtUtc: '2026-08-10 10:00:00', paymentStatus: OrderPaymentStatus::AWAITING_PAYMENT->name, status: OrderStatus::RESERVED->name);

        $this->assertNull($this->lineFor($organizerId));
    }

    public function test_sms_is_only_billed_when_the_add_on_is_enabled(): void
    {
        $offId = $this->createOrganizer('SMS Off');
        $onId = $this->createOrganizer('SMS On');
        $this->createBillingSettings($offId, smsEnabled: false);
        $this->createBillingSettings($onId, smsEnabled: true);

        foreach ([$offId, $onId] as $organizerId) {
            $eventId = $this->createEvent($organizerId);
            $orderId = $this->createSoldOrder($eventId, tickets: 1, createdAtUtc: '2026-08-05 10:00:00');
            $this->createSms($organizerId, $eventId, $orderId, '2026-08-05 10:00:05');
            $this->createSms($organizerId, $eventId, $orderId, '2026-08-20 10:00:05', SmsMessageType::REFUND_NOTICE);
            $this->createSms($organizerId, $eventId, $orderId, '2026-08-21 10:00:05', status: SmsMessageStatus::FAILED);
            $this->createSms($organizerId, $eventId, $orderId, '2026-09-01 10:00:05');
        }

        $off = $this->lineFor($offId);
        $on = $this->lineFor($onId);

        $this->assertFalse($off->smsEnabled);
        $this->assertSame(0, $off->smsSent);
        $this->assertSame(0.0, $off->smsTotal);
        $this->assertSame(6.0, $off->total);

        $this->assertTrue($on->smsEnabled);
        $this->assertSame(2, $on->smsSent);
        $this->assertSame(1.0, $on->smsTotal);
        $this->assertSame(7.0, $on->total);
    }

    public function test_custom_fees_override_the_defaults(): void
    {
        $defaultId = $this->createOrganizer('Default Fees');
        $customId = $this->createOrganizer('Custom Fees');
        $this->createBillingSettings($customId, smsEnabled: true, platformFee: 4.50, smsFee: 0.35);

        foreach ([$defaultId, $customId] as $organizerId) {
            $eventId = $this->createEvent($organizerId);
            $orderId = $this->createSoldOrder($eventId, tickets: 10, createdAtUtc: '2026-08-05 10:00:00');
            $this->createSms($organizerId, $eventId, $orderId, '2026-08-05 10:00:05');
        }

        $default = $this->lineFor($defaultId);
        $custom = $this->lineFor($customId);

        $this->assertSame(6.0, $default->platformFeePerTicket);
        $this->assertSame(60.0, $default->total);

        $this->assertSame(4.5, $custom->platformFeePerTicket);
        $this->assertSame(45.0, $custom->platformFeeTotal);
        $this->assertSame(0.35, $custom->smsTotal);
        $this->assertSame(45.35, $custom->total);
    }

    public function test_the_summary_lists_every_active_organizer_with_a_grand_total(): void
    {
        $firstId = $this->createOrganizer('Zebra Events');
        $secondId = $this->createOrganizer('alpha club');
        $this->createOrganizer('Idle Org');

        $this->createSoldOrder($this->createEvent($firstId), tickets: 2, createdAtUtc: '2026-08-05 10:00:00');
        $this->createSoldOrder($this->createEvent($secondId), tickets: 3, createdAtUtc: '2026-08-06 10:00:00');

        $summary = app(MonthlyBillingSummaryService::class)->build(CarbonImmutable::parse(self::AUGUST));

        $this->assertSame(['alpha club', 'Zebra Events'], $summary->lines->pluck('organizerName')->all());
        $this->assertSame(30.0, $summary->grandTotal);
        $this->assertSame('augusti 2026', $summary->monthLabel());
    }

    public function test_the_mail_is_sent_in_swedish_with_a_csv_attachment_and_the_run_is_recorded(): void
    {
        $organizerId = $this->createOrganizer('Lördagsklubben');
        $this->createBillingSettings($organizerId, smsEnabled: true);
        $eventId = $this->createEvent($organizerId);
        $orderId = $this->createSoldOrder($eventId, tickets: 12, createdAtUtc: '2026-08-05 10:00:00', refunded: true);
        $this->createSms($organizerId, $eventId, $orderId, '2026-08-05 10:00:05');

        $outcome = app(MonthlyBillingSummaryDispatchService::class)->send(CarbonImmutable::parse(self::AUGUST));

        $this->assertSame(BillingSummaryOutcome::SENT, $outcome);

        Mail::assertQueued(MonthlyBillingSummaryMail::class, function (MonthlyBillingSummaryMail $mail) {
            $mail->assertHasTo('billing@biljettera.test');
            $mail->assertHasSubject('Faktureringsunderlag augusti 2026');

            $html = str_replace(["\u{00A0}", '&nbsp;'], ' ', $mail->render());
            $this->assertStringContainsString('Lördagsklubben', $html);
            $this->assertStringContainsString('varav 12 återköpta — påverkar ej avgiften', $html);
            $this->assertStringContainsString('12 × 6,00 kr', $html);
            $this->assertStringContainsString('SMS skickade', $html);
            $this->assertStringContainsString('Totalt att fakturera', $html);
            $this->assertStringContainsString('72,50 kr', $html);
            $this->assertStringContainsString('Avgift per såld biljett, återköp påverkar ej. SMS debiteras per skickat meddelande.', $html);

            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertSame('faktureringsunderlag-2026-08.csv', $attachments[0]->as);

            return true;
        });

        $run = DB::table('billing_summary_runs')->where('period_start', self::AUGUST)->first();
        $this->assertSame('billing@biljettera.test', $run->recipient);
        $this->assertSame(1, (int) $run->organizer_count);
        $this->assertSame('72.50', $run->grand_total);
        $this->assertNotNull($run->sent_at);
    }

    public function test_a_month_is_never_sent_twice_unless_forced(): void
    {
        $this->createSoldOrder($this->createEvent($this->createOrganizer('Org')), tickets: 1, createdAtUtc: '2026-08-05 10:00:00');
        $service = app(MonthlyBillingSummaryDispatchService::class);
        $period = CarbonImmutable::parse(self::AUGUST);

        $this->assertSame(BillingSummaryOutcome::SENT, $service->send($period));
        $this->assertSame(BillingSummaryOutcome::ALREADY_SENT, $service->send($period));
        Mail::assertQueued(MonthlyBillingSummaryMail::class, 1);

        $this->assertSame(BillingSummaryOutcome::SENT, $service->send($period, force: true));
        Mail::assertQueued(MonthlyBillingSummaryMail::class, 2);
        $this->assertSame(1, DB::table('billing_summary_runs')->where('period_start', self::AUGUST)->count());
    }

    public function test_a_preview_to_another_address_does_not_record_the_run(): void
    {
        $this->createSoldOrder($this->createEvent($this->createOrganizer('Org')), tickets: 1, createdAtUtc: '2026-08-05 10:00:00');
        $service = app(MonthlyBillingSummaryDispatchService::class);
        $period = CarbonImmutable::parse(self::AUGUST);

        $this->assertSame(BillingSummaryOutcome::SENT, $service->send($period, previewRecipient: 'me@example.test'));
        Mail::assertQueued(MonthlyBillingSummaryMail::class, fn (MonthlyBillingSummaryMail $mail) => $mail->hasTo('me@example.test'));
        $this->assertSame(0, DB::table('billing_summary_runs')->where('period_start', self::AUGUST)->count());

        $this->assertSame(BillingSummaryOutcome::SENT, $service->send($period));
    }

    public function test_an_empty_month_sends_a_short_nothing_to_invoice_mail(): void
    {
        $this->createOrganizer('Quiet Org');

        app(MonthlyBillingSummaryDispatchService::class)->send(CarbonImmutable::parse(self::AUGUST));

        Mail::assertQueued(MonthlyBillingSummaryMail::class, function (MonthlyBillingSummaryMail $mail) {
            $mail->assertHasSubject('Faktureringsunderlag augusti 2026 – inget att fakturera');
            $this->assertStringContainsString('Det finns inget att fakturera.', $mail->render());
            $this->assertSame([], $mail->attachments());

            return true;
        });

        $this->assertSame(0, (int) DB::table('billing_summary_runs')->where('period_start', self::AUGUST)->value('organizer_count'));
    }

    public function test_nothing_is_sent_without_a_configured_recipient(): void
    {
        Config::set('billing.summary_email', null);

        $outcome = app(MonthlyBillingSummaryDispatchService::class)->send(CarbonImmutable::parse(self::AUGUST));

        $this->assertSame(BillingSummaryOutcome::NO_RECIPIENT, $outcome);
        Mail::assertNothingQueued();
    }

    private function lineFor(int $organizerId): ?OrganizerBillingLineDTO
    {
        return app(MonthlyBillingSummaryService::class)
            ->build(CarbonImmutable::parse(self::AUGUST))
            ->lines
            ->first(fn (OrganizerBillingLineDTO $line) => $line->organizerId === $organizerId);
    }

    private function createOrganizer(string $name): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => $name,
            'email' => 'org-'.uniqid().'@billing.test',
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEvent(int $organizerId): int
    {
        return DB::table('events')->insertGetId([
            'title' => 'Billing Event',
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'organizer_id' => $organizerId,
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSoldOrder(
        int $eventId,
        int $tickets,
        string $createdAtUtc,
        bool $refunded = false,
        string $paymentStatus = OrderPaymentStatus::PAYMENT_RECEIVED->name,
        string $status = OrderStatus::COMPLETED->name,
    ): int {
        $suffix = uniqid();
        $total = 100.00 * $tickets;

        $productId = DB::table('products')->insertGetId([
            'title' => 'Ticket',
            'event_id' => $eventId,
            'order' => 1,
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productPriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $productId,
            'price' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.$suffix,
            'public_id' => 'pub_'.$suffix,
            'event_id' => $eventId,
            'currency' => 'SEK',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'refund_status' => $refunded ? OrderRefundStatus::REFUNDED->name : null,
            'session_id' => 'session-'.$suffix,
            'email' => 'buyer@billing.test',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'locale' => 'se',
            'total_before_additions' => $total,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_gross' => $total,
            'total_refunded' => $refunded ? $total : 0,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'item_name' => 'Ticket',
            'product_type' => 'TICKET',
            'quantity' => $tickets,
            'price' => 100.00,
            'total_before_additions' => $total,
            'total_gross' => $total,
        ]);

        return $orderId;
    }

    private function createBillingSettings(int $organizerId, bool $smsEnabled, float $platformFee = 6.00, float $smsFee = 0.50): void
    {
        DB::table('organizer_billing_settings')->insert([
            'organizer_id' => $organizerId,
            'sms_enabled' => $smsEnabled,
            'sms_sender_name' => null,
            'platform_fee_per_ticket' => $platformFee,
            'sms_fee_per_message' => $smsFee,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSms(
        int $organizerId,
        int $eventId,
        int $orderId,
        string $sentAtUtc,
        SmsMessageType $type = SmsMessageType::TICKET,
        SmsMessageStatus $status = SmsMessageStatus::SENT,
    ): void {
        DB::table('sms_messages')->insert([
            'organizer_id' => $organizerId,
            'event_id' => $eventId,
            'order_id' => $orderId,
            'type' => $type->name,
            'status' => $status->name,
            'recipient' => '+46701234567',
            'sender' => 'Biljettera',
            'parts' => 1,
            'sent_at' => $status === SmsMessageStatus::SENT ? $sentAtUtc : null,
            'created_at' => $sentAtUtc,
            'updated_at' => $sentAtUtc,
        ]);
    }
}
