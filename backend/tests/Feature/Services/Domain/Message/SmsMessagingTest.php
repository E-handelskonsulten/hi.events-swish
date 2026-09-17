<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Message;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use HiEvents\Services\Domain\Marketing\MarketingOptOutTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class SmsMessagingTest extends SwishFeatureTestCase
{
    private const ELKS_URL = 'https://api.46elks.com/a1/sms';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sms.enabled', true);
        Config::set('sms.dry_run', false);
        Config::set('sms.default_sender', 'Biljettera');
        Config::set('sms.elks.base_url', 'https://api.46elks.com/a1');
        Config::set('sms.elks.username', 'u-test');
        Config::set('sms.elks.password', 'p-test');
        Config::set('app.frontend_url', 'https://demo.test');
        Config::set('app.saas_mode_enabled', false);

        DB::table('accounts')->where('id', $this->accountId)->update([
            'account_verified_at' => now(),
            'account_messaging_tier_id' => DB::table('account_messaging_tiers')->where('name', 'Trusted')->value('id'),
        ]);
        DB::table('organizer_billing_settings')->insert([
            'organizer_id' => $this->organizerId,
            'sms_enabled' => true,
            'sms_sender_name' => 'Klubben',
            'sms_lead_hours' => null,
            'platform_fee_per_ticket' => 6.00,
            'sms_fee_per_message' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([self::ELKS_URL => Http::response(['id' => 's-1', 'status' => 'created', 'parts' => 1])]);
    }

    public function test_preview_counts_scopes_and_cost_for_a_service_sms(): void
    {
        $this->paidOrder('anna@example.test', '46700000001', consented: true);
        $this->paidOrder('erik@example.test', '46700000002', consented: false);
        $this->paidOrder('maja@example.test', null, consented: true);

        $preview = $this->postJson("/events/{$this->eventId}/messages/preview", [
            'message_type' => 'ALL_ATTENDEES',
            'channel' => 'BOTH',
            'purpose' => 'SERVICE',
            'sms_body' => str_repeat('Hej! Dörrarna öppnar 19.00. ', 6),
        ], $this->authHeaders())->assertOk()->json('data');

        $this->assertSame(3, $preview['email_recipients']);
        $this->assertSame(2, $preview['sms_recipients']);
        $this->assertSame(0, $preview['excluded_without_consent']);
        $this->assertSame(1, $preview['excluded_without_phone']);
        $this->assertTrue($preview['sms_available']);
        $this->assertSame('Klubben', $preview['sms_sender']);
        $this->assertSame('GSM-7', $preview['sms_encoding']);
        $this->assertSame(2, $preview['sms_parts']);
        $this->assertEquals(1.0, $preview['sms_cost_per_recipient']);
        $this->assertEquals(2.0, $preview['sms_total_cost']);
        $this->assertFalse($preview['requires_confirmation']);
    }

    public function test_marketing_preview_only_counts_consented_recipients_and_reserves_room_for_the_opt_out_link(): void
    {
        $this->paidOrder('anna@example.test', '46700000001', consented: true);
        $this->paidOrder('erik@example.test', '46700000002', consented: false);
        $this->paidOrder('maja@example.test', '46700000003', consented: false);

        $preview = $this->postJson("/events/{$this->eventId}/messages/preview", [
            'message_type' => 'ALL_ATTENDEES',
            'channel' => 'BOTH',
            'purpose' => 'MARKETING',
            'sms_body' => 'Nytt event nästa månad!',
        ], $this->authHeaders())->assertOk()->json('data');

        $this->assertSame(1, $preview['email_recipients']);
        $this->assertSame(1, $preview['sms_recipients']);
        $this->assertSame(2, $preview['excluded_without_consent']);
        $this->assertGreaterThan(20, $preview['sms_opt_out_suffix_length']);
        $this->assertSame(1, $preview['sms_parts']);
    }

    public function test_a_service_sms_goes_to_every_ticket_holder_with_a_number_and_is_tracked_for_billing(): void
    {
        $this->paidOrder('anna@example.test', '46700000001', consented: false);
        $this->paidOrder('erik@example.test', '46700000002', consented: true);
        $this->paidOrder('maja@example.test', null, consented: true);

        $message = $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Dörrarna öppnar 19.00.'])
            ->assertOk()->json('data');

        $this->assertSame('SMS', $message['channel']);
        $this->assertSame('SERVICE', $message['purpose']);
        $this->assertSame(2, $message['recipient_count']);
        $this->assertEquals(1.0, $message['sms_cost']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['from'] === 'Klubben' && $request['message'] === 'Dörrarna öppnar 19.00.');

        $rows = DB::table('sms_messages')->where('message_id', $message['id'])->get();
        $this->assertSame(['SERVICE', 'SERVICE'], $rows->pluck('type')->all());
        $this->assertSame(['SENT', 'SENT'], $rows->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['+46700000001', '+46700000002'], $rows->pluck('recipient')->all());
        $this->assertSame(2, DB::table('outgoing_messages')->where('message_id', $message['id'])->where('subject', 'SMS')->count());

        $counts = app(SmsMessagesRepositoryInterface::class)->countSentPerOrganizerBetween(now()->subHour(), now()->addHour());
        $this->assertSame(['SERVICE' => 2], $counts[$this->organizerId]);
    }

    public function test_a_service_sms_skips_blocklisted_numbers_and_records_why(): void
    {
        Config::set('sms.blocklist_enforced', true);
        Config::set('sms.blocked_recipients', ['+46701234567']);
        $this->paidOrder('mss@example.test', '46701234567', consented: true);
        $this->paidOrder('erik@example.test', '46700000002', consented: true);

        $message = $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Dörrarna öppnar 19.00.'])
            ->assertOk()->json('data');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['to'] === '+46700000002');

        $rows = DB::table('sms_messages')->where('message_id', $message['id'])->get()->keyBy('recipient');
        $this->assertSame('CANCELLED', $rows['+46701234567']->status);
        $this->assertStringContainsString('blocklist', $rows['+46701234567']->error_message);
        $this->assertSame('SENT', $rows['+46700000002']->status);

        $counts = app(SmsMessagesRepositoryInterface::class)->countSentPerOrganizerBetween(now()->subHour(), now()->addHour());
        $this->assertSame(['SERVICE' => 1], $counts[$this->organizerId]);
    }

    public function test_a_marketing_sms_never_reaches_buyers_without_consent_and_carries_the_opt_out_link(): void
    {
        $this->paidOrder('anna@example.test', '46700000001', consented: false);
        $consentedOrderId = $this->paidOrder('erik@example.test', '46700000002', consented: true);

        $message = $this->send(['channel' => 'SMS', 'purpose' => 'MARKETING', 'sms_body' => 'Nytt event nästa månad!'])
            ->assertOk()->json('data');

        $optOutUrl = app(MarketingOptOutTokenService::class)->urlForOrder($consentedOrderId);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['to'] === '+46700000002'
            && $request['message'] === "Nytt event nästa månad!\nAvregistrera: {$optOutUrl}");
        $this->assertSame('MARKETING', DB::table('sms_messages')->where('message_id', $message['id'])->value('type'));
    }

    public function test_the_opt_out_link_withdraws_consent_for_every_order_of_that_buyer(): void
    {
        $first = $this->paidOrder('erik@example.test', '46700000002', consented: true);
        $second = $this->paidOrder('erik@example.test', '46700000002', consented: true);
        $other = $this->paidOrder('anna@example.test', '46700000001', consented: true);
        $token = app(MarketingOptOutTokenService::class)->tokenForOrder($first);

        $this->flushSession();
        $this->getJson("/public/marketing/opt-out/{$token}")->assertOk()
            ->assertJsonPath('data.opted_in', true)
            ->assertJsonPath('data.organizer_name', 'Swish Test Organizer');

        $this->postJson("/public/marketing/opt-out/{$token}")->assertOk()->assertJsonPath('data.opted_in', false);

        $this->assertNull(DB::table('orders')->where('id', $first)->value('opted_into_marketing_at'));
        $this->assertNull(DB::table('orders')->where('id', $second)->value('opted_into_marketing_at'));
        $this->assertNotNull(DB::table('orders')->where('id', $other)->value('opted_into_marketing_at'));

        $this->getJson('/public/marketing/opt-out/'.$first.'.wrongsig00')->assertNotFound();

        $this->send(['channel' => 'SMS', 'purpose' => 'MARKETING', 'sms_body' => 'Hej igen'])->assertOk();
        Http::assertSent(fn (Request $request) => $request['to'] === '+46700000001');
        Http::assertNotSent(fn (Request $request) => $request['to'] === '+46700000002');
    }

    public function test_sms_cannot_be_sent_when_the_organizer_has_not_enabled_the_add_on(): void
    {
        DB::table('organizer_billing_settings')->where('organizer_id', $this->organizerId)->update(['sms_enabled' => false]);
        $this->paidOrder('anna@example.test', '46700000001', consented: true);

        $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Hej'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel']);

        Http::assertNothingSent();
    }

    public function test_sending_to_more_than_a_hundred_recipients_requires_typing_the_event_name(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->paidOrder("buyer{$i}@example.test", sprintf('4670%07d', $i), consented: true);
        }

        $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Hej alla'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);
        Http::assertNothingSent();

        $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Hej alla', 'confirmation' => 'Wrong name'])
            ->assertUnprocessable();

        $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => 'Hej alla', 'confirmation' => 'Swish Test Event'])
            ->assertOk();
        Http::assertSentCount(101);
    }

    public function test_an_sms_only_message_is_listed_under_its_text_when_no_subject_is_given(): void
    {
        $this->paidOrder('anna@example.test', '46701111111', consented: false);

        $message = $this->send(['channel' => 'SMS', 'purpose' => 'SERVICE', 'sms_body' => "Ingång via baksidan.\nVälkomna!", 'subject' => null, 'message' => null])
            ->assertOk()
            ->json('data');

        $this->assertSame('Ingång via baksidan. Välkomna!', $message['subject']);
        $this->assertStringContainsString('Ingång via baksidan.', $message['message']);
        $this->assertSame('SMS', $message['channel']);
    }

    public function test_a_marketing_email_skips_buyers_without_consent(): void
    {
        $this->paidOrder('anna@example.test', null, consented: false);
        $this->paidOrder('erik@example.test', null, consented: true);

        $message = $this->send(['channel' => 'EMAIL', 'purpose' => 'MARKETING', 'subject' => 'Nyheter', 'message' => '<p>Hej</p>'])
            ->assertOk()->json('data');

        $recipients = DB::table('outgoing_messages')->where('message_id', $message['id'])->pluck('recipient')->all();
        $this->assertSame(['erik@example.test'], $recipients);
        $this->assertSame(1, $message['recipient_count']);
        $this->assertNull($message['sms_cost']);
    }

    private function send(array $payload)
    {
        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);

        return $this->postJson("/events/{$this->eventId}/messages", $payload + [
            'message_type' => 'ALL_ATTENDEES',
            'subject' => 'Info',
            'message' => '<p>Info</p>',
        ], $this->authHeaders());
    }

    private function paidOrder(string $email, ?string $phone, bool $consented): int
    {
        $suffix = uniqid();
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.$suffix,
            'public_id' => 'pub_'.$suffix,
            'event_id' => $this->eventId,
            'currency' => 'SEK',
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'session_id' => 'session-'.$suffix,
            'email' => $email,
            'phone' => $phone,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'locale' => 'se',
            'total_before_additions' => 100,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_gross' => 100,
            'opted_into_marketing_at' => $consented ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendees')->insert([
            'short_id' => 'a_'.$suffix,
            'public_id' => 'A-'.strtoupper($suffix),
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'event_id' => $this->eventId,
            'status' => AttendeeStatus::ACTIVE->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
