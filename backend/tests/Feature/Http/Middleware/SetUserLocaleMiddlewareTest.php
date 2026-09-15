<?php

namespace Tests\Feature\Http\Middleware;

use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SetUserLocaleMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    private const LOGIN_ROUTE = '/auth/login';

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);
    }

    public function test_swedish_browser_language_renders_swedish_messages(): void
    {
        $response = $this->failedLogin(['Accept-Language' => 'sv-SE,sv;q=0.9,en;q=0.8']);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Fel användarnamn eller lösenord');
    }

    public function test_bare_sv_language_tag_renders_swedish_messages(): void
    {
        $response = $this->failedLogin(['Accept-Language' => 'sv']);

        $response->assertJsonPath('message', 'Fel användarnamn eller lösenord');
    }

    public function test_english_browser_language_renders_english_messages(): void
    {
        $response = $this->failedLogin(['Accept-Language' => 'en-US,en;q=0.9']);

        $response->assertJsonPath('message', 'Username or Password are incorrect');
    }

    private function failedLogin(array $headers)
    {
        $user = User::factory()->password('correct-password-123')->withAccount()->create();

        return $this->postJson(self::LOGIN_ROUTE, [
            'email' => $user->email,
            'password' => 'wrong-password-123',
        ], $headers);
    }
}
