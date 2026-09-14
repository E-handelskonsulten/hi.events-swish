<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentRequestService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\Swish\SwishClientFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

abstract class SwishFeatureTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const PAYEE_ALIAS = '1234679304';

    protected const PAYER_ALIAS = '0701234567';

    protected const ORDER_TOTAL = 25.00;

    protected MockHandler $swishHttp;

    protected User $user;

    protected string $authToken;

    protected int $accountId;

    protected int $organizerId;

    protected int $eventId;

    protected int $productId;

    protected int $productPriceId;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('swish.enabled', true);
        Config::set('swish.environment', 'mss');
        Config::set('swish.payee_alias', self::PAYEE_ALIAS);
        Config::set('swish.cert_path', '/certs/merchant.pem');
        Config::set('swish.key_path', '/certs/merchant.key');
        Config::set('swish.ca_path', '/certs/root.pem');
        Config::set('swish.callback_base_url', 'https://callbacks.test/api');

        $this->swishHttp = new MockHandler;
        $client = new Client([
            'base_uri' => 'https://swish.test',
            'handler' => HandlerStack::create($this->swishHttp),
            'http_errors' => true,
        ]);

        $clientFactory = Mockery::mock(SwishClientFactory::class);
        $clientFactory->shouldReceive('create')->andReturn($client);
        $this->app->instance(SwishClientFactory::class, $clientFactory);

        $domainEvents = Mockery::mock(DomainEventDispatcherService::class);
        $domainEvents->shouldReceive('dispatch')->byDefault();
        $this->app->instance(DomainEventDispatcherService::class, $domainEvents);

        $this->user = User::factory()->withAccount()->create();
        $this->accountId = $this->user->accounts()->first()->id;
        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);

        $now = now()->toDateTimeString();

        $this->organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Swish Test Organizer',
            'email' => 'organizer-'.uniqid().'@swish.test',
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'Swish Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->user->id,
            'organizer_id' => $this->organizerId,
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'payment_providers' => json_encode([PaymentProviders::SWISH->value], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('event_statistics')->insert([
            'event_id' => $this->eventId,
            'unique_views' => 0,
            'total_views' => 0,
            'sales_total_gross' => 0,
            'total_tax' => 0,
            'sales_total_before_additions' => 0,
            'total_fee' => 0,
            'products_sold' => 0,
            'orders_created' => 0,
            'total_refunded' => 0,
            'attendees_registered' => 0,
            'orders_cancelled' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->productId = DB::table('products')->insertGetId([
            'title' => 'Entry ticket',
            'event_id' => $this->eventId,
            'order' => 1,
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->productPriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $this->productId,
            'price' => self::ORDER_TOTAL,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->assertSame(0, $this->swishHttp->count(), 'Every queued Swish response must be consumed by the scenario');

        parent::tearDown();
    }

    protected function createReservedOrder(?string $reservedUntil = null, float $total = self::ORDER_TOTAL): int
    {
        $now = now()->toDateTimeString();
        $suffix = uniqid();

        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.$suffix,
            'public_id' => 'pub_'.$suffix,
            'event_id' => $this->eventId,
            'currency' => 'SEK',
            'status' => OrderStatus::RESERVED->name,
            'payment_status' => OrderPaymentStatus::AWAITING_PAYMENT->name,
            'reserved_until' => $reservedUntil ?? now()->addMinutes(15)->toDateTimeString(),
            'session_id' => 'session-'.$suffix,
            'email' => 'buyer@swish.test',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'locale' => 'en',
            'total_before_additions' => $total,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_gross' => $total,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'item_name' => 'Entry ticket',
            'product_type' => 'TICKET',
            'quantity' => 1,
            'price' => $total,
            'total_before_additions' => $total,
            'total_gross' => $total,
        ]);

        DB::table('attendees')->insert([
            'short_id' => 'a_'.$suffix,
            'public_id' => 'apub_'.$suffix,
            'email' => 'buyer@swish.test',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'event_id' => $this->eventId,
            'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $orderId;
    }

    protected function createPendingSwishPayment(int $orderId, float $amount = self::ORDER_TOTAL): array
    {
        $instructionUuid = SwishPaymentRequestService::newInstructionUuid();
        $now = now()->toDateTimeString();

        $paymentId = DB::table('swish_payments')->insertGetId([
            'order_id' => $orderId,
            'instruction_uuid' => $instructionUuid,
            'environment' => 'mss',
            'flow' => SwishCheckoutFlow::ECOMMERCE->value,
            'payee_alias' => self::PAYEE_ALIAS,
            'payer_alias' => self::PAYER_ALIAS,
            'amount' => $amount,
            'currency' => 'SEK',
            'status' => SwishPaymentStatus::CREATED->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$paymentId, $instructionUuid];
    }

    protected function createPaidSwishOrder(
        string $paymentReference = 'PAYREF123',
        ?string $datePaid = null,
        float $total = self::ORDER_TOTAL,
    ): int {
        $orderId = $this->createReservedOrder(total: $total);
        [$paymentId] = $this->createPendingSwishPayment($orderId, $total);

        DB::table('orders')->where('id', $orderId)->update([
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'payment_provider' => PaymentProviders::SWISH->value,
        ]);

        DB::table('swish_payments')->where('id', $paymentId)->update([
            'status' => SwishPaymentStatus::PAID->value,
            'payment_reference' => $paymentReference,
            'payer_alias' => '46701234567',
            'date_paid' => $datePaid ?? now()->toDateTimeString(),
        ]);

        return $orderId;
    }

    protected function swishPaymentPayload(string $instructionUuid, int $orderId, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => $instructionUuid,
            'payeePaymentReference' => (string) $orderId,
            'paymentReference' => 'REF'.substr($instructionUuid, 0, 10),
            'callbackUrl' => 'https://callbacks.test/api/public/webhooks/swish/payments',
            'payerAlias' => '46701234567',
            'payeeAlias' => self::PAYEE_ALIAS,
            'amount' => number_format(self::ORDER_TOTAL, 2, '.', ''),
            'currency' => 'SEK',
            'message' => 'Swish Test Event',
            'status' => $status,
            'dateCreated' => now()->subMinute()->toIso8601String(),
            'datePaid' => $status === 'PAID' ? now()->toIso8601String() : null,
            'errorCode' => null,
            'errorMessage' => null,
        ], $overrides);
    }

    protected function queueSwishJson(array $payload, int $status = 200): void
    {
        $this->swishHttp->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    protected function queueSwishResponse(int $status, array $headers = [], string $body = ''): void
    {
        $this->swishHttp->append(new Response($status, $headers, $body));
    }

    protected function postPaymentCallback(array $payload): TestResponse
    {
        return $this->postJson('/public/webhooks/swish/payments', $payload);
    }

    protected function authHeaders(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->authToken];
    }

    protected function orderRow(int $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->first();
    }

    protected function swishPaymentRow(int $paymentId): object
    {
        return DB::table('swish_payments')->where('id', $paymentId)->first();
    }
}
