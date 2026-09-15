<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Infrastructure\Sms;

use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Services\Infrastructure\Sms\ElksSmsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElksSmsClientTest extends TestCase
{
    private const URL = 'https://api.46elks.com/a1/sms';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sms.elks.username', 'u-test');
        Config::set('sms.elks.password', 'p-test');
        Config::set('sms.dry_run', false);
    }

    public function test_it_posts_a_basic_auth_form_request_and_parses_the_response(): void
    {
        Http::fake([self::URL => Http::response(['id' => 's1', 'status' => 'created', 'parts' => 1, 'cost' => 3500])]);

        $sent = app(ElksSmsClient::class)->send('+46701234567', 'Biljettera', 'Hi!');

        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('u-test:p-test'))
            && $request->isForm()
            && $request['to'] === '+46701234567'
            && $request['from'] === 'Biljettera'
            && $request['message'] === 'Hi!'
            && ! isset($request['dryrun']));

        $this->assertSame('s1', $sent->providerMessageId);
        $this->assertSame('created', $sent->status);
        $this->assertSame(1, $sent->parts);
        $this->assertFalse($sent->dryRun);
    }

    public function test_dry_run_mode_asks_46elks_to_validate_without_sending(): void
    {
        Config::set('sms.dry_run', true);
        Http::fake([self::URL => Http::response(['status' => 'dryrun', 'estimated_cost' => 3500, 'parts' => 1])]);

        $sent = app(ElksSmsClient::class)->send('+46701234567', 'Biljettera', 'Hi!');

        Http::assertSent(fn (Request $request) => $request['dryrun'] === 'yes');
        $this->assertTrue($sent->dryRun);
    }

    public function test_missing_credentials_fail_before_any_request_is_made(): void
    {
        Config::set('sms.elks.password', null);
        Http::fake();

        $this->expectException(SmsDeliveryException::class);

        try {
            app(ElksSmsClient::class)->send('+46701234567', 'Biljettera', 'Hi!');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_provider_errors_are_raised_with_the_response_body(): void
    {
        Http::fake([self::URL => Http::response('Invalid from', 403)]);

        $this->expectException(SmsDeliveryException::class);
        $this->expectExceptionMessage('HTTP 403');

        app(ElksSmsClient::class)->send('+46701234567', 'Biljettera', 'Hi!');
    }
}
