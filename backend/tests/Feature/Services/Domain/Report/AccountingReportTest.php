<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Report;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Report\OrganizerReports\AccountingReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    use DatabaseTransactions;

    private int $accountId;

    private int $userId;

    private int $organizerId;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->withAccount()->create();
        $this->userId = $user->id;
        $this->accountId = $user->accounts()->first()->id;

        $this->organizerId = $this->makeOrganizer();
        $this->eventId = $this->makeEvent($this->organizerId, 'Club Night');
    }

    public function test_sales_are_grouped_per_day_event_and_provider_with_vat_split_by_rate(): void
    {
        $this->makeOrder(gross: 100, tax: 20, fee: 5, taxes: [['name' => 'Moms', 'rate' => 25, 'type' => 'PERCENTAGE', 'value' => 20]], createdAt: '2026-09-10 20:30:00');
        $this->makeOrder(gross: 100, tax: 10.71, fee: 0, taxes: [['name' => 'Moms', 'rate' => 12, 'type' => 'PERCENTAGE', 'value' => 10.71]], createdAt: '2026-09-10 21:00:00');
        $this->makeOrder(gross: 50, tax: 2.83, fee: 0, taxes: [['name' => 'Moms', 'rate' => 0.06, 'type' => 'PERCENTAGE', 'value' => 2.83]], createdAt: '2026-09-11 01:00:00');
        $this->makeOrder(gross: 30, tax: 0, fee: 0, taxes: [], createdAt: '2026-09-10 12:00:00', provider: PaymentProviders::STRIPE);
        $this->makeOrder(gross: 999, tax: 0, fee: 0, taxes: [], createdAt: '2026-09-10 22:00:00', status: OrderStatus::RESERVED);

        $otherOrganizerEventId = $this->makeEvent($this->makeOrganizer(), 'Other Organizer Event');
        $this->makeOrder(gross: 777, tax: 0, fee: 0, taxes: [], createdAt: '2026-09-10 22:00:00', eventId: $otherOrganizerEventId);

        $rows = $this->generate('2026-09-01', '2026-09-30');

        $this->assertCount(3, $rows);

        $swishSep10 = $this->findRow($rows, '2026-09-10', PaymentProviders::SWISH->value, AccountingReport::LINE_TYPE_SALE);
        $this->assertSame(2, (int) $swishSep10->transaction_count);
        $this->assertMoney(200, $swishSep10->gross_amount);
        $this->assertMoney(5, $swishSep10->service_fee_amount);
        $this->assertMoney(20, $swishSep10->vat_25_amount);
        $this->assertMoney(10.71, $swishSep10->vat_12_amount);
        $this->assertMoney(0, $swishSep10->vat_6_amount);
        $this->assertMoney(30.71, $swishSep10->vat_total_amount);
        $this->assertMoney(169.29, $swishSep10->net_amount);
        $this->assertSame('Club Night', $swishSep10->event_name);
        $this->assertSame('SEK', $swishSep10->currency);

        $stripeSep10 = $this->findRow($rows, '2026-09-10', PaymentProviders::STRIPE->value, AccountingReport::LINE_TYPE_SALE);
        $this->assertMoney(30, $stripeSep10->gross_amount);

        $swishSep11 = $this->findRow($rows, '2026-09-11', PaymentProviders::SWISH->value, AccountingReport::LINE_TYPE_SALE);
        $this->assertMoney(50, $swishSep11->gross_amount);
        $this->assertMoney(2.83, $swishSep11->vat_6_amount);
    }

    public function test_dates_are_bucketed_in_the_organizer_timezone(): void
    {
        $this->makeOrder(gross: 100, tax: 0, fee: 0, taxes: [], createdAt: '2026-09-10 22:30:00');

        $rows = $this->generate('2026-09-01', '2026-09-30');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-11', $rows->first()->date);
    }

    public function test_refunds_are_separate_negative_lines_with_proportional_vat_and_fees(): void
    {
        $orderId = $this->makeOrder(gross: 100, tax: 20, fee: 5, taxes: [['name' => 'Moms', 'rate' => 25, 'type' => 'PERCENTAGE', 'value' => 20]], createdAt: '2026-09-10 20:30:00');
        $this->makeRefund($orderId, 50, '2026-09-12 10:00:00');
        $this->makeRefund($orderId, 25, '2026-09-12 11:00:00', status: 'failed');

        $rows = $this->generate('2026-09-01', '2026-09-30');

        $this->assertCount(2, $rows);

        $refund = $this->findRow($rows, '2026-09-12', PaymentProviders::SWISH->value, AccountingReport::LINE_TYPE_REFUND);
        $this->assertSame(1, (int) $refund->transaction_count);
        $this->assertMoney(-50, $refund->gross_amount);
        $this->assertMoney(-2.5, $refund->service_fee_amount);
        $this->assertMoney(-10, $refund->vat_25_amount);
        $this->assertMoney(-10, $refund->vat_total_amount);
        $this->assertMoney(-40, $refund->net_amount);

        $this->assertMoney(50, $rows->sum(fn (object $row) => (float) $row->gross_amount));
    }

    public function test_date_range_and_currency_filters_are_applied(): void
    {
        $this->makeOrder(gross: 100, tax: 0, fee: 0, taxes: [], createdAt: '2026-08-31 12:00:00');
        $this->makeOrder(gross: 60, tax: 0, fee: 0, taxes: [], createdAt: '2026-09-15 12:00:00');

        $this->assertCount(1, $this->generate('2026-09-01', '2026-09-30'));
        $this->assertCount(0, $this->generate('2026-09-01', '2026-09-30', 'EUR'));
        $this->assertCount(1, $this->generate('2026-09-01', '2026-09-30', 'SEK'));
    }

    private function generate(string $start, string $end, ?string $currency = null)
    {
        return $this->app->make(AccountingReport::class)->generateReport(
            organizerId: $this->organizerId,
            currency: $currency,
            startDate: Carbon::parse($start, 'Europe/Stockholm'),
            endDate: Carbon::parse($end, 'Europe/Stockholm'),
        );
    }

    private function findRow($rows, string $date, string $provider, string $lineType): object
    {
        $row = $rows->first(fn (object $row) => $row->date === $date && $row->payment_provider === $provider && $row->line_type === $lineType);

        $this->assertNotNull($row, "Expected a $lineType line for $provider on $date");

        return $row;
    }

    private function assertMoney(float $expected, mixed $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.005);
    }

    private function makeOrganizer(): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Organizer '.uniqid(),
            'email' => 'organizer-'.uniqid().'@report.test',
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $organizerId, string $title): int
    {
        return DB::table('events')->insertGetId([
            'title' => $title,
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'organizer_id' => $organizerId,
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(
        float $gross,
        float $tax,
        float $fee,
        array $taxes,
        string $createdAt,
        PaymentProviders $provider = PaymentProviders::SWISH,
        OrderStatus $status = OrderStatus::COMPLETED,
        ?int $eventId = null,
    ): int {
        $suffix = uniqid();

        return DB::table('orders')->insertGetId([
            'short_id' => 'o_'.$suffix,
            'public_id' => 'pub_'.$suffix,
            'event_id' => $eventId ?? $this->eventId,
            'currency' => 'SEK',
            'status' => $status->name,
            'payment_provider' => $provider->value,
            'total_before_additions' => $gross - $tax - $fee,
            'total_tax' => $tax,
            'total_fee' => $fee,
            'total_gross' => $gross,
            'taxes_and_fees_rollup' => json_encode(['taxes' => $taxes, 'fees' => []], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function makeRefund(int $orderId, float $amount, string $createdAt, string $status = 'succeeded'): void
    {
        DB::table('order_refunds')->insert([
            'order_id' => $orderId,
            'payment_provider' => PaymentProviders::SWISH->value,
            'refund_id' => 'refund_'.uniqid(),
            'amount' => $amount,
            'currency' => 'SEK',
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
