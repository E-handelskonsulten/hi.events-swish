<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Billing;

use HiEvents\Services\Domain\Billing\DTO\MonthlyBillingSummaryDTO;
use HiEvents\Services\Domain\Billing\DTO\OrganizerBillingLineDTO;

class BillingSummaryCsvBuilder
{
    private const SEPARATOR = ';';

    public function build(MonthlyBillingSummaryDTO $summary): string
    {
        $period = $summary->periodStart->format('Y-m');
        $rows = [[
            'organizer_id',
            'organizer',
            'period',
            'sold_orders',
            'sold_tickets',
            'refunded_tickets',
            'platform_fee_per_ticket',
            'platform_fee_total',
            'sms_enabled',
            'sms_sent',
            'sms_ticket',
            'sms_refund_notice',
            'sms_service',
            'sms_marketing',
            'sms_fee_per_message',
            'sms_total',
            'organizer_total',
            'gross_sales',
            'currency',
        ]];

        foreach ($summary->lines as $line) {
            /** @var OrganizerBillingLineDTO $line */
            $rows[] = [
                $line->organizerId,
                $line->organizerName,
                $period,
                $line->soldOrders,
                $line->soldTickets,
                $line->refundedTickets,
                $this->amount($line->platformFeePerTicket),
                $this->amount($line->platformFeeTotal),
                $line->smsEnabled ? 'yes' : 'no',
                $line->smsSent,
                $line->smsSentByType['TICKET'] ?? 0,
                $line->smsSentByType['REFUND_NOTICE'] ?? 0,
                $line->smsSentByType['SERVICE'] ?? 0,
                $line->smsSentByType['MARKETING'] ?? 0,
                $this->amount($line->smsFeePerMessage),
                $this->amount($line->smsTotal),
                $this->amount($line->total),
                $this->amount($line->grossSales),
                $summary->currency,
            ];
        }

        $rows[] = ['', 'TOTAL', $period, '', '', '', '', '', '', '', '', '', '', '', '', '', $this->amount($summary->grandTotal), '', $summary->currency];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, self::SEPARATOR, '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function amount(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }
}
