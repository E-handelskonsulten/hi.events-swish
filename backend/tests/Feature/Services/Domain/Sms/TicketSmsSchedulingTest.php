<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Sms;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Helper\DateHelper;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Sms\TicketSmsScheduleService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class TicketSmsSchedulingTest extends SwishFeatureTestCase
{
    private const ELKS_URL = 'https://api.46elks.com/a1/sms';

    private const START_STOCKHOLM = '2026-10-10 20:00:00';

    private const START_UTC = '2026-10-10 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sms.enabled', true);
        Config::set('sms.dry_run', false);
        Config::set('sms.elks.username', 'u-test');
        Config::set('sms.elks.password', 'p-test');
        Config::set('app.frontend_url', 'https://demo.test');

        $orderEmails = Mockery::mock(SendOrderDetailsService::class);
        $orderEmails->shouldReceive('sendOrderSummaryAndTicketEmails')->byDefault();
        $this->app->instance(SendOrderDetailsService::class, $orderEmails);

        Http::fake([self::ELKS_URL => Http::response(['id' => 's-1', 'status' => 'created', 'parts' => 1])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_ticket_link_opens_the_attendee_ticket_page_without_a_checkout_session(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 19:00:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();
        $attendeeShortId = DB::table('attendees')->where('order_id', $orderId)->value('short_id');
        $ticketUrl = "https://demo.test/product/{$this->eventId}/{$attendeeShortId}";

        Http::assertSent(fn (Request $request) => str_ends_with($request['message'], $ticketUrl));

        $this->flushSession();
        $this->getJson("/public/events/{$this->eventId}/attendees/{$attendeeShortId}")
            ->assertOk()
            ->assertJsonPath('data.short_id', $attendeeShortId);
    }

    public function test_an_early_purchase_is_scheduled_for_n_hours_before_the_stockholm_start(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->createOccurrence(DateHelper::convertToUTC(self::START_STOCKHOLM, 'Europe/Stockholm'));

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertNothingSent();
        $row = $this->smsRow($orderId);
        $this->assertSame(SmsMessageStatus::SCHEDULED->name, $row->status);
        $this->assertSame('2026-10-10 15:00:00', $row->scheduled_for);
        $this->assertSame('+46701234567', $row->recipient);
        $this->assertNull($row->sent_at);
    }

    public function test_a_purchase_within_n_hours_of_the_start_is_sent_immediately(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 16:00:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId)->status);
    }

    public function test_a_purchase_exactly_n_hours_before_the_start_is_sent_immediately(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 15:00:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId)->status);
    }

    public function test_a_purchase_after_the_start_is_sent_immediately(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 19:30:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId)->status);
    }

    public function test_the_organizer_lead_time_is_used(): void
    {
        $this->enableSms(leadHours: 6);
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();

        $this->assertSame('2026-10-10 12:00:00', $this->smsRow($orderId)->scheduled_for);
    }

    public function test_a_scheduled_sms_is_sent_once_it_is_due_and_counted_in_that_month(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->createOccurrence('2026-10-01 18:00:00');
        $orderId = $this->completeOrderThroughSwishCallback();
        Http::assertNothingSent();

        Carbon::setTestNow('2026-10-01 14:59:00');
        $this->assertSame(0, app(TicketSmsScheduleService::class)->dispatchDue());
        Http::assertNothingSent();

        Carbon::setTestNow('2026-10-01 15:00:00');
        $this->assertSame(1, app(TicketSmsScheduleService::class)->dispatchDue());

        Http::assertSentCount(1);
        $row = $this->smsRow($orderId);
        $this->assertSame(SmsMessageStatus::SENT->name, $row->status);
        $this->assertSame('2026-10-01 15:00:00', $row->sent_at);
        $this->assertSame(1, DB::table('sms_messages')->where('order_id', $orderId)->count());

        $this->assertSame(0, app(TicketSmsScheduleService::class)->dispatchDue());
        Http::assertSentCount(1);
    }

    public function test_moving_the_event_reschedules_the_pending_sms(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $occurrenceId = $this->createOccurrence(self::START_UTC);
        $orderId = $this->completeOrderThroughSwishCallback();
        $this->assertSame('2026-10-10 15:00:00', $this->smsRow($orderId)->scheduled_for);

        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);
        $this->putJson("/events/{$this->eventId}/occurrences/{$occurrenceId}", [
            'start_date' => '2026-10-12 20:00:00',
            'end_date' => '2026-10-12 22:00:00',
        ], $this->authHeaders())->assertOk();

        $movedStart = DB::table('event_occurrences')->where('id', $occurrenceId)->value('start_date');
        $this->assertSame(
            Carbon::parse($movedStart, 'UTC')->subHours(3)->toDateTimeString(),
            $this->smsRow($orderId)->scheduled_for,
        );
        Http::assertNothingSent();
    }

    public function test_moving_the_event_closer_sends_the_pending_sms_on_the_next_run(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $occurrenceId = $this->createOccurrence(self::START_UTC);
        $orderId = $this->completeOrderThroughSwishCallback();

        DB::table('event_occurrences')->where('id', $occurrenceId)->update(['start_date' => '2026-10-10 11:00:00']);
        app(TicketSmsScheduleService::class)->reschedule(['event_id' => $this->eventId]);

        $this->assertSame('2026-10-10 10:00:00', $this->smsRow($orderId)->scheduled_for);

        app(TicketSmsScheduleService::class)->dispatchDue();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId)->status);
    }

    public function test_immediate_mode_sends_on_completion_no_matter_how_far_away_the_event_is(): void
    {
        $this->enableSms(leadHours: null);
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->createOccurrence(self::START_UTC);

        $orderId = $this->completeOrderThroughSwishCallback();

        Http::assertSentCount(1);
        $row = $this->smsRow($orderId);
        $this->assertSame(SmsMessageStatus::SENT->name, $row->status);
        $this->assertNull($row->scheduled_for);
    }

    public function test_switching_an_organizer_to_immediate_releases_its_held_messages(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->createOccurrence(self::START_UTC);
        $orderId = $this->completeOrderThroughSwishCallback();
        $this->assertSame(SmsMessageStatus::SCHEDULED->name, $this->smsRow($orderId)->status);

        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);
        $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => null,
            'sms_lead_hours' => null,
        ], $this->authHeaders())->assertOk()->assertJsonPath('data.sms_lead_hours', null);

        $this->assertSame('2026-10-10 10:00:00', $this->smsRow($orderId)->scheduled_for);

        app(TicketSmsScheduleService::class)->dispatchDue();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId)->status);
    }

    public function test_switching_from_immediate_to_hours_only_affects_future_orders(): void
    {
        $this->enableSms(leadHours: null);
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->createOccurrence(self::START_UTC);
        $sentOrderId = $this->completeOrderThroughSwishCallback();
        Http::assertSentCount(1);

        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);
        $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => null,
            'sms_lead_hours' => 3,
        ], $this->authHeaders())->assertOk()->assertJsonPath('data.sms_lead_hours', 3);

        $laterOrderId = $this->completeOrderThroughSwishCallback();

        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($sentOrderId)->status);
        $this->assertSame(SmsMessageStatus::SCHEDULED->name, $this->smsRow($laterOrderId)->status);
        $this->assertSame('2026-10-10 15:00:00', $this->smsRow($laterOrderId)->scheduled_for);
    }

    public function test_a_full_refund_cancels_the_pending_ticket_sms_and_sends_the_refund_notice(): void
    {
        $this->enableSms();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->createOccurrence(self::START_UTC);
        $orderId = $this->completeOrderThroughSwishCallback();
        Http::assertNothingSent();

        DB::table('orders')->where('id', $orderId)->update([
            'total_refunded' => self::ORDER_TOTAL,
            'refund_status' => OrderRefundStatus::REFUNDED->name,
        ]);
        event(new OrderEvent(DomainEventType::ORDER_REFUNDED, $orderId));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['message'], 'has been refunded'));
        $this->assertSame(SmsMessageStatus::CANCELLED->name, $this->smsRow($orderId, SmsMessageType::TICKET)->status);
        $this->assertSame(SmsMessageStatus::SENT->name, $this->smsRow($orderId, SmsMessageType::REFUND_NOTICE)->status);

        Carbon::setTestNow('2026-10-10 15:00:00');
        app(TicketSmsScheduleService::class)->dispatchDue();
        Http::assertSentCount(1);
    }

    private function enableSms(?int $leadHours = 3): void
    {
        DB::table('organizer_billing_settings')->insert([
            'organizer_id' => $this->organizerId,
            'sms_enabled' => true,
            'sms_sender_name' => null,
            'sms_lead_hours' => $leadHours,
            'platform_fee_per_ticket' => 6.00,
            'sms_fee_per_message' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOccurrence(string $startUtc): int
    {
        return DB::table('event_occurrences')->insertGetId([
            'short_id' => 'occ_'.uniqid(),
            'event_id' => $this->eventId,
            'start_date' => $startUtc,
            'end_date' => Carbon::parse($startUtc, 'UTC')->addHours(2)->toDateTimeString(),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function completeOrderThroughSwishCallback(): int
    {
        $orderId = $this->createReservedOrder();
        $occurrenceId = DB::table('event_occurrences')->where('event_id', $this->eventId)->value('id');
        DB::table('order_items')->where('order_id', $orderId)->update(['event_occurrence_id' => $occurrenceId]);
        [, $instructionUuid] = $this->createPendingSwishPayment($orderId);

        $paid = $this->swishPaymentPayload($instructionUuid, $orderId, 'PAID');
        $this->queueSwishJson($paid);
        $this->postPaymentCallback($paid)->assertOk();

        return $orderId;
    }

    private function smsRow(int $orderId, SmsMessageType $type = SmsMessageType::TICKET): object
    {
        return DB::table('sms_messages')->where('order_id', $orderId)->where('type', $type->name)->first();
    }
}
