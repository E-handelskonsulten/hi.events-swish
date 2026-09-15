<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Organizers\Billing;

use HiEvents\Jobs\Sms\RescheduleTicketSmsJob;
use HiEvents\Models\Account;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class OrganizerBillingSettingsTest extends SwishFeatureTestCase
{
    public function test_get_returns_defaults_when_the_organizer_has_no_settings(): void
    {
        Config::set('sms.enabled', true);
        Config::set('sms.default_sender', 'Biljettera');

        $response = $this->getJson("/organizers/{$this->organizerId}/billing-settings", $this->authHeaders());

        $response->assertOk();
        $this->assertNull($response->json('data'));
        $this->assertTrue($response->json('meta.sms_configured'));
        $this->assertSame('Biljettera', $response->json('meta.default_sms_sender_name'));
        $this->assertSame(0.5, $response->json('meta.default_sms_fee_per_message'));
    }

    public function test_saving_creates_settings_with_default_fees_and_never_exposes_the_platform_fee(): void
    {
        $response = $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => 'Klubben',
            'sms_lead_hours' => 5,
        ], $this->authHeaders());

        $response->assertOk();
        $this->assertTrue($response->json('data.sms_enabled'));
        $this->assertSame(5, $response->json('data.sms_lead_hours'));
        $this->assertSame('Klubben', $response->json('data.sms_sender_name'));
        $this->assertSame(0.5, $response->json('data.sms_fee_per_message'));
        $this->assertArrayNotHasKey('platform_fee_per_ticket', $response->json('data'));

        $row = DB::table('organizer_billing_settings')->where('organizer_id', $this->organizerId)->first();
        $this->assertSame('6.00', $row->platform_fee_per_ticket);
    }

    public function test_saving_again_updates_the_existing_row_and_keeps_custom_fees(): void
    {
        $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => 'Klubben',
            'sms_lead_hours' => 3,
        ], $this->authHeaders())->assertOk();

        DB::table('organizer_billing_settings')->where('organizer_id', $this->organizerId)->update(['platform_fee_per_ticket' => 4.50]);

        $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => false,
            'sms_sender_name' => '',
            'sms_lead_hours' => 3,
        ], $this->authHeaders())->assertOk();

        $rows = DB::table('organizer_billing_settings')->where('organizer_id', $this->organizerId)->get();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->sms_enabled);
        $this->assertNull($rows[0]->sms_sender_name);
        $this->assertSame('4.50', $rows[0]->platform_fee_per_ticket);
    }

    #[DataProvider('invalidSenderNames')]
    public function test_sender_name_must_be_a_valid_alphanumeric_sender_id(string $sender): void
    {
        $response = $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => $sender,
            'sms_lead_hours' => 3,
        ], $this->authHeaders());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sms_sender_name']);
    }

    public static function invalidSenderNames(): array
    {
        return [
            'too short' => ['AB'],
            'too long' => ['Biljetteraab'],
            'starts with digit' => ['1Klubb'],
            'contains space' => ['Min Klubb'],
            'contains swedish letter' => ['Lördag'],
        ];
    }

    #[DataProvider('invalidLeadHours')]
    public function test_lead_hours_must_be_between_1_and_24(mixed $hours): void
    {
        $response = $this->putJson("/organizers/{$this->organizerId}/billing-settings", [
            'sms_enabled' => true,
            'sms_sender_name' => null,
            'sms_lead_hours' => $hours,
        ], $this->authHeaders());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sms_lead_hours']);
    }

    public static function invalidLeadHours(): array
    {
        return [
            'zero' => [0],
            'too many' => [25],
            'fraction' => [2.5],
            'missing' => [null],
        ];
    }

    public function test_changing_the_lead_time_reschedules_pending_ticket_sms(): void
    {
        Bus::fake([RescheduleTicketSmsJob::class]);
        $payload = ['sms_enabled' => true, 'sms_sender_name' => null, 'sms_lead_hours' => 3];

        $this->putJson("/organizers/{$this->organizerId}/billing-settings", $payload, $this->authHeaders())->assertOk();
        $this->putJson("/organizers/{$this->organizerId}/billing-settings", $payload, $this->authHeaders())->assertOk();
        Bus::assertNotDispatched(RescheduleTicketSmsJob::class);

        $this->putJson("/organizers/{$this->organizerId}/billing-settings", ['sms_lead_hours' => 8] + $payload, $this->authHeaders())->assertOk();
        Bus::assertDispatched(RescheduleTicketSmsJob::class, fn (RescheduleTicketSmsJob $job) => $job->where === ['organizer_id' => $this->organizerId]);
    }

    public function test_other_accounts_are_forbidden_from_reading_the_settings(): void
    {
        $otherOrganizerId = DB::table('organizers')->insertGetId([
            'account_id' => Account::factory()->create()->id,
            'name' => 'Other Organizer',
            'email' => 'other-org-'.uniqid().'@example.test',
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/organizers/{$otherOrganizerId}/billing-settings", $this->authHeaders())->assertForbidden();
    }
}
