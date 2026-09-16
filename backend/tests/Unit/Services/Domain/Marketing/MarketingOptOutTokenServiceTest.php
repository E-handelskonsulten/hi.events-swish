<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Marketing;

use HiEvents\Services\Domain\Marketing\MarketingOptOutTokenService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MarketingOptOutTokenServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        Config::set('app.frontend_url', 'https://demo.test');
    }

    public function test_a_token_round_trips_to_its_order(): void
    {
        $service = app(MarketingOptOutTokenService::class);

        $token = $service->tokenForOrder(42);

        $this->assertMatchesRegularExpression('/^42\.[A-Za-z0-9_-]{10}$/', $token);
        $this->assertSame(42, $service->orderIdFromToken($token));
        $this->assertSame('https://demo.test/u/'.$token, $service->urlForOrder(42));
    }

    public function test_a_tampered_or_foreign_token_is_rejected(): void
    {
        $service = app(MarketingOptOutTokenService::class);
        $token = $service->tokenForOrder(42);
        [, $signature] = explode('.', $token);

        $this->assertNull($service->orderIdFromToken('43.'.$signature));
        $this->assertNull($service->orderIdFromToken('42.'.strrev($signature)));
        $this->assertNull($service->orderIdFromToken('42'));
        $this->assertNull($service->orderIdFromToken('abc.def'));

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        $this->assertNull(app(MarketingOptOutTokenService::class)->orderIdFromToken($token));
    }
}
