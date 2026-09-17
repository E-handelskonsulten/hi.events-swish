<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Tax;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Domain\Billing\DTO\OrganizerBillingLineDTO;
use HiEvents\Services\Domain\Billing\MonthlyBillingSummaryService;
use HiEvents\Services\Domain\Report\OrganizerReports\AccountingReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class CombinedServiceFeeOrderTest extends SwishFeatureTestCase
{
    private int $expensiveProductId;

    private int $expensivePriceId;

    private int $occurrenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurrenceId = DB::table('event_occurrences')->insertGetId([
            'short_id' => 'occ_'.uniqid(),
            'event_id' => $this->eventId,
            'start_date' => now()->addDays(10)->toDateTimeString(),
            'end_date' => now()->addDays(10)->addHours(2)->toDateTimeString(),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_prices')->where('id', $this->productPriceId)->update(['price' => 100.00]);
        // Display mode is presentation only: fees must land in totals, Swish amounts and refunds regardless.
        DB::table('event_settings')->where('event_id', $this->eventId)->update(['price_display_mode' => 'CHECKOUT']);

        $this->expensiveProductId = DB::table('products')->insertGetId([
            'title' => 'VIP ticket',
            'event_id' => $this->eventId,
            'order' => 2,
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->expensivePriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $this->expensiveProductId,
            'price' => 350.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_combined_fee_needs_a_fixed_amount_and_existing_types_do_not(): void
    {
        $this->postJson("/accounts/{$this->accountId}/taxes-and-fees", array_replace($this->feePayload(), ['fixed_amount' => null]), $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fixed_amount']);

        $fixed = $this->postJson("/accounts/{$this->accountId}/taxes-and-fees", [
            'name' => 'Bokningsavgift',
            'type' => 'FEE',
            'calculation_type' => 'FIXED',
            'rate' => 2.5,
            'fixed_amount' => 99,
            'description' => null, 'is_active' => true,
            'is_default' => false,
        ], $this->authHeaders())->assertOk()->json('data');

        $this->assertSame('FIXED', $fixed['calculation_type']);
        $this->assertNull($fixed['fixed_amount']);
    }

    public function test_a_multi_ticket_order_shows_one_fee_line_summed_per_ticket(): void
    {
        $this->createServiceFee();

        $order = $this->createOrder();

        $this->assertSame(550.0, (float) $order['total_before_additions']);
        $this->assertSame(17.5, (float) $order['total_fee']);
        $this->assertSame(567.5, (float) $order['total_gross']);
        $this->assertCount(1, $order['taxes_and_fees_rollup']['fees']);
        $this->assertSame('Serviceavgift', $order['taxes_and_fees_rollup']['fees'][0]['name']);
        $this->assertSame(17.5, (float) $order['taxes_and_fees_rollup']['fees'][0]['value']);

        $items = DB::table('order_items')->where('order_id', $this->orderIdOf($order))->orderBy('id')->get();
        $this->assertSame(['10.00', '7.50'], $items->pluck('total_service_fee')->all());
        $this->assertSame(['210.00', '357.50'], $items->pluck('total_gross')->all());
    }

    public function test_a_full_refund_returns_the_ticket_price_and_the_fee(): void
    {
        $this->createServiceFee();
        $order = $this->createOrder();
        $orderId = $this->payOrder($order);

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/X']);
        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 567.50,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $refund = DB::table('swish_refunds')->where('order_id', $orderId)->first();
        $this->assertSame('567.50', $refund->amount);

        $paid = $this->refundPayload($refund->instruction_uuid, $orderId, '567.50');
        $this->queueSwishJson($paid);
        $this->postJson('/public/webhooks/swish/refunds', $paid)->assertOk();

        $row = $this->orderRow($orderId);
        $this->assertSame('567.50', $row->total_refunded);
        $this->assertSame(OrderRefundStatus::REFUNDED->name, $row->refund_status);

        $this->postJson("/events/{$this->eventId}/orders/{$orderId}/refund", [
            'amount' => 1,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertStatus(422);
    }

    public function test_the_fee_flows_into_the_accounting_report_and_leaves_the_billing_basis_untouched(): void
    {
        $this->createServiceFee();
        $this->payOrder($this->createOrder());

        $report = app(AccountingReport::class)->generateReport(
            organizerId: $this->organizerId,
            currency: 'SEK',
            startDate: Carbon::now()->subDay(),
            endDate: Carbon::now()->addDay(),
        );
        $sale = $report->firstWhere('line_type', AccountingReport::LINE_TYPE_SALE);
        $this->assertSame(567.5, (float) $sale->gross_amount);
        $this->assertSame(17.5, (float) $sale->service_fee_amount);

        $line = app(MonthlyBillingSummaryService::class)
            ->build(CarbonImmutable::now('Europe/Stockholm'))
            ->lines
            ->first(fn (OrganizerBillingLineDTO $line) => $line->organizerId === $this->organizerId);
        $this->assertSame(3, $line->soldTickets);
        $this->assertSame(18.0, $line->platformFeeTotal);
        $this->assertSame(567.5, $line->grossSales);
    }

    public function test_single_type_fees_on_the_same_order_still_add_up_as_before(): void
    {
        $fixedId = $this->postJson("/accounts/{$this->accountId}/taxes-and-fees", [
            'name' => 'Bokningsavgift', 'type' => 'FEE', 'calculation_type' => 'FIXED', 'rate' => 2.5, 'description' => null, 'is_active' => true, 'is_default' => false,
        ], $this->authHeaders())->assertOk()->json('data.id');
        $percentId = $this->postJson("/accounts/{$this->accountId}/taxes-and-fees", [
            'name' => 'Plattform', 'type' => 'FEE', 'calculation_type' => 'PERCENTAGE', 'rate' => 10, 'description' => null, 'is_active' => true, 'is_default' => false,
        ], $this->authHeaders())->assertOk()->json('data.id');
        $this->attachFee($fixedId);
        $this->attachFee($percentId);

        $order = $this->createOrder();

        $this->assertSame(62.5, (float) $order['total_fee']);
        $this->assertSame(612.5, (float) $order['total_gross']);
        $this->assertCount(2, $order['taxes_and_fees_rollup']['fees']);
    }

    private function feePayload(): array
    {
        return [
            'name' => 'Serviceavgift',
            'type' => 'FEE',
            'calculation_type' => 'FIXED_PLUS_PERCENTAGE',
            'rate' => 1,
            'fixed_amount' => 4,
            'description' => null, 'is_active' => true,
            'is_default' => false,
        ];
    }

    private function createServiceFee(): void
    {
        $fee = $this->postJson("/accounts/{$this->accountId}/taxes-and-fees", $this->feePayload(), $this->authHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('FIXED_PLUS_PERCENTAGE', $fee['calculation_type']);
        $this->assertSame(4.0, (float) $fee['fixed_amount']);
        $this->attachFee($fee['id']);
    }

    private function attachFee(int $feeId): void
    {
        foreach ([$this->productId, $this->expensiveProductId] as $productId) {
            DB::table('product_taxes_and_fees')->insert(['product_id' => $productId, 'tax_and_fee_id' => $feeId]);
        }
    }

    private function createOrder(): array
    {
        $this->flushSession();

        return $this->postJson("/public/events/{$this->eventId}/order", [
            'products' => [
                ['product_id' => $this->productId, 'event_occurrence_id' => $this->occurrenceId, 'quantities' => [['price_id' => $this->productPriceId, 'quantity' => 2]]],
                ['product_id' => $this->expensiveProductId, 'event_occurrence_id' => $this->occurrenceId, 'quantities' => [['price_id' => $this->expensivePriceId, 'quantity' => 1]]],
            ],
        ])->assertCreated()->json('data');
    }

    private function payOrder(array $order): int
    {
        $orderId = $this->orderIdOf($order);
        $attendee = ['first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@swish.test', 'email_confirmation' => 'buyer@swish.test'];

        $this->putJson("/public/events/{$this->eventId}/order/{$order['short_id']}?session_identifier={$order['session_identifier']}", [
            'order' => $attendee + [
                'questions' => [],
                'address' => ['address_line_1' => 'Storgatan 1', 'city' => 'Stockholm', 'zip_or_postal_code' => '11122', 'country' => 'SE'],
            ],
            'products' => [
                $attendee + ['product_id' => $this->productId, 'product_price_id' => $this->productPriceId],
                $attendee + ['product_id' => $this->productId, 'product_price_id' => $this->productPriceId],
                $attendee + ['product_id' => $this->expensiveProductId, 'product_price_id' => $this->expensivePriceId],
            ],
        ])->assertOk();
        [, $instructionUuid] = $this->createPendingSwishPayment($orderId, (float) $order['total_gross']);
        $paid = $this->swishPaymentPayload($instructionUuid, $orderId, 'PAID', ['amount' => '567.50']);
        $this->queueSwishJson($paid);
        $this->postPaymentCallback($paid)->assertOk();
        $this->assertSame(OrderStatus::COMPLETED->name, $this->orderRow($orderId)->status);

        return $orderId;
    }

    private function orderIdOf(array $order): int
    {
        return (int) DB::table('orders')->where('short_id', $order['short_id'])->value('id');
    }

    private function refundPayload(string $instructionUuid, int $orderId, string $amount): array
    {
        return [
            'id' => $instructionUuid,
            'paymentReference' => 'REFUNDREF'.substr($instructionUuid, 0, 6),
            'payerPaymentReference' => (string) $orderId,
            'originalPaymentReference' => 'PAYREF123',
            'callbackUrl' => 'https://callbacks.test/api/public/webhooks/swish/refunds',
            'payerAlias' => self::PAYEE_ALIAS,
            'payeeAlias' => '46701234567',
            'amount' => $amount,
            'currency' => 'SEK',
            'message' => 'Refund',
            'status' => 'PAID',
            'dateCreated' => now()->subMinute()->toIso8601String(),
            'datePaid' => now()->toIso8601String(),
            'errorCode' => null,
            'errorMessage' => null,
        ];
    }
}
