<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Billing;

use Carbon\CarbonImmutable;
use HiEvents\Services\Domain\Billing\BillingSummaryCsvBuilder;
use HiEvents\Services\Domain\Billing\DTO\MonthlyBillingSummaryDTO;
use HiEvents\Services\Domain\Billing\DTO\OrganizerBillingLineDTO;
use Tests\TestCase;

class BillingSummaryCsvBuilderTest extends TestCase
{
    public function test_it_writes_a_semicolon_separated_sheet_with_swedish_decimals_and_a_total_row(): void
    {
        $periodStart = CarbonImmutable::parse('2026-08-01', 'Europe/Stockholm');
        $summary = new MonthlyBillingSummaryDTO(
            periodStart: $periodStart,
            periodEnd: $periodStart->endOfMonth(),
            currency: 'SEK',
            lines: collect([
                new OrganizerBillingLineDTO(
                    organizerId: 7,
                    organizerName: 'Lördagsklubben; Demo',
                    soldOrders: 9,
                    soldTickets: 12,
                    refundedTickets: 1,
                    platformFeePerTicket: 6.0,
                    platformFeeTotal: 72.0,
                    smsEnabled: true,
                    smsSent: 8,
                    smsFeePerMessage: 0.5,
                    smsTotal: 4.0,
                    total: 76.0,
                    grossSales: 1234.5,
                ),
            ]),
            grandTotal: 76.0,
        );

        $csv = (new BillingSummaryCsvBuilder)->build($summary);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertSame('organizer_id;organizer;period;sold_orders;sold_tickets;refunded_tickets;platform_fee_per_ticket;platform_fee_total;sms_enabled;sms_sent;sms_fee_per_message;sms_total;organizer_total;gross_sales;currency', $lines[0]);
        $this->assertSame('7;"Lördagsklubben; Demo";2026-08;9;12;1;6,00;72,00;yes;8;0,50;4,00;76,00;1234,50;SEK', $lines[1]);
        $this->assertSame(';TOTAL;2026-08;;;;;;;;;;76,00;;SEK', $lines[2]);
    }
}
