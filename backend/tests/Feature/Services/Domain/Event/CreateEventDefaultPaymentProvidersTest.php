<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Event;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class CreateEventDefaultPaymentProvidersTest extends SwishFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('organizer_settings')->insert([
            'organizer_id' => $this->organizerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_new_event_defaults_to_swish_when_swish_is_configured_and_stripe_is_not(): void
    {
        Config::set('services.stripe.secret_key', null);

        $eventId = $this->createEvent();

        $this->assertSame(['SWISH'], $this->providersOf($eventId));
    }

    public function test_a_new_event_shows_fees_in_checkout_and_does_not_mail_the_organizer_per_order(): void
    {
        $eventId = $this->createEvent();

        $settings = DB::table('event_settings')->where('event_id', $eventId)->first();
        $this->assertSame('CHECKOUT', $settings->price_display_mode);
        $this->assertFalse((bool) $settings->notify_organizer_of_new_orders);
    }

    public function test_a_new_event_still_defaults_to_stripe_when_stripe_is_configured(): void
    {
        Config::set('services.stripe.secret_key', 'sk_test_configured');

        $eventId = $this->createEvent();

        $this->assertSame(['STRIPE'], $this->providersOf($eventId));
    }

    public function test_a_new_event_defaults_to_stripe_when_neither_gateway_is_configured(): void
    {
        Config::set('services.stripe.secret_key', null);
        Config::set('swish.enabled', false);

        $eventId = $this->createEvent();

        $this->assertSame(['STRIPE'], $this->providersOf($eventId));
    }

    private function createEvent(): int
    {
        return (int) $this->withHeaders($this->authHeaders())
            ->postJson('/events', [
                'title' => 'Fredagsklubben',
                'organizer_id' => $this->organizerId,
                'timezone' => 'Europe/Stockholm',
                'currency' => 'SEK',
                'start_date' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertOk()
            ->json('data.id');
    }

    /**
     * @return string[]
     */
    private function providersOf(int $eventId): array
    {
        return json_decode((string) DB::table('event_settings')->where('event_id', $eventId)->value('payment_providers'), true);
    }
}
