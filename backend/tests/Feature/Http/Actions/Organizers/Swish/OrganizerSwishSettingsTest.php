<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Organizers\Swish;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class OrganizerSwishSettingsTest extends SwishFeatureTestCase
{
    public function test_get_reports_environment_fallback_when_organizer_has_no_settings(): void
    {
        $response = $this->getJson("/organizers/{$this->organizerId}/swish-settings", $this->authHeaders());

        $response->assertOk();
        $this->assertNull($response->json('data'));
        $this->assertTrue($response->json('meta.environment_fallback_configured'));
        $this->assertSame(self::PAYEE_ALIAS, $response->json('meta.environment_fallback_payee_alias'));
    }

    public function test_saving_enabled_settings_verifies_the_connection_and_stores_them(): void
    {
        $this->queueSwishResponse(404);

        $response = $this->putJson("/organizers/{$this->organizerId}/swish-settings", [
            'enabled' => true,
            'environment' => 'mss',
            'payee_alias' => '1231181189',
            'cert_path' => '/certs/other.pem',
            'key_path' => '/certs/other.key',
            'ca_path' => '/certs/root.pem',
            'key_passphrase' => 'secret',
        ], $this->authHeaders());

        $response->assertOk();
        $this->assertTrue($response->json('data.enabled'));
        $this->assertSame('1231181189', $response->json('data.payee_alias'));
        $this->assertTrue($response->json('data.has_key_passphrase'));
        $this->assertNotNull($response->json('data.last_verified_at'));
        $this->assertNull($response->json('data.last_verification_error'));
        $this->assertArrayNotHasKey('key_passphrase', $response->json('data'));

        $row = DB::table('organizer_swish_settings')->where('organizer_id', $this->organizerId)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('secret', $row->key_passphrase);
    }

    public function test_saving_enabled_settings_fails_when_swish_rejects_the_certificate(): void
    {
        $this->queueSwishResponse(401);

        $response = $this->putJson("/organizers/{$this->organizerId}/swish-settings", [
            'enabled' => true,
            'environment' => 'mss',
            'payee_alias' => '1231181189',
            'cert_path' => '/certs/other.pem',
            'key_path' => '/certs/other.key',
            'ca_path' => '/certs/root.pem',
        ], $this->authHeaders());

        $response->assertStatus(422)->assertJsonValidationErrors(['cert_path']);
        $this->assertSame(0, DB::table('organizer_swish_settings')->where('organizer_id', $this->organizerId)->where('enabled', true)->count());
    }

    public function test_saving_disabled_settings_does_not_contact_swish(): void
    {
        $response = $this->putJson("/organizers/{$this->organizerId}/swish-settings", [
            'enabled' => false,
            'environment' => 'production',
            'payee_alias' => null,
            'cert_path' => null,
            'key_path' => null,
            'ca_path' => null,
        ], $this->authHeaders());

        $response->assertOk();
        $this->assertFalse($response->json('data.enabled'));
        $this->assertSame('production', $response->json('data.environment'));
    }

    public function test_payee_alias_is_required_and_numeric_when_enabled(): void
    {
        $response = $this->putJson("/organizers/{$this->organizerId}/swish-settings", [
            'enabled' => true,
            'environment' => 'mss',
            'payee_alias' => '12ab',
            'cert_path' => '/certs/other.pem',
            'key_path' => '/certs/other.key',
            'ca_path' => '/certs/root.pem',
        ], $this->authHeaders());

        $response->assertStatus(422)->assertJsonValidationErrors(['payee_alias']);
    }

    public function test_connection_test_reports_success_from_the_environment_fallback(): void
    {
        $this->queueSwishResponse(404);

        $response = $this->postJson("/organizers/{$this->organizerId}/swish-settings/test", [], $this->authHeaders());

        $response->assertOk();
        $this->assertTrue($response->json('data.success'));
        $this->assertSame('env', $response->json('data.source'));
        $this->assertSame(self::PAYEE_ALIAS, $response->json('data.payee_alias'));
    }

    public function test_connection_test_reports_a_missing_configuration(): void
    {
        Config::set('swish.enabled', false);

        $response = $this->postJson("/organizers/{$this->organizerId}/swish-settings/test", [], $this->authHeaders());

        $response->assertOk();
        $this->assertFalse($response->json('data.success'));
        $this->assertNull($response->json('data.source'));
    }
}
