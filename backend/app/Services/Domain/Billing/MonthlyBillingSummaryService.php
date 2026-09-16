<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Billing;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use HiEvents\Services\Domain\Billing\DTO\MonthlyBillingSummaryDTO;
use HiEvents\Services\Domain\Billing\DTO\OrganizerBillingLineDTO;
use HiEvents\Services\Domain\Report\OrganizerReports\AccountingReport;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class MonthlyBillingSummaryService
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly SmsMessagesRepositoryInterface $smsMessagesRepository,
        private readonly AccountingReport $accountingReport,
        private readonly DatabaseManager $db,
        private readonly Repository $config,
    ) {}

    public function build(CarbonImmutable $periodStart): MonthlyBillingSummaryDTO
    {
        $timezone = $this->config->get('billing.timezone');
        $periodStart = $periodStart->setTimezone($timezone)->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $currency = $this->config->get('billing.currency');

        $smsCounts = $this->smsMessagesRepository->countSentPerOrganizerBetween(
            $periodStart->utc(),
            $periodStart->addMonth()->utc(),
        );

        $lines = $this->organizerRepository->all()
            ->map(fn (OrganizerDomainObject $organizer) => $this->buildLine(
                $organizer,
                $periodStart,
                $periodEnd,
                $smsCounts[$organizer->getId()] ?? [],
                $currency,
            ))
            ->filter(fn (OrganizerBillingLineDTO $line) => $line->soldTickets > 0 || $line->smsSent > 0)
            ->sortBy(fn (OrganizerBillingLineDTO $line) => mb_strtolower($line->organizerName))
            ->values();

        return new MonthlyBillingSummaryDTO(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            currency: $currency,
            lines: $lines,
            grandTotal: Currency::round($lines->sum(fn (OrganizerBillingLineDTO $line) => $line->total)),
        );
    }

    private function buildLine(
        OrganizerDomainObject $organizer,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $smsSentByType,
        string $currency,
    ): OrganizerBillingLineDTO {
        $smsSent = array_sum($smsSentByType);
        $settings = $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $organizer->getId(),
        ]);

        $platformFee = $this->platformFee($settings);
        $smsFee = $this->smsFee($settings);
        $smsEnabled = (bool) $settings?->getSmsEnabled();

        $counts = $this->ticketCounts($organizer->getId(), $periodStart, $periodEnd);
        $grossSales = $this->grossSales($organizer->getId(), $periodStart, $periodEnd, $currency);

        $platformFeeTotal = Currency::round($counts->sold_tickets * $platformFee);
        $smsTotal = $smsEnabled ? Currency::round($smsSent * $smsFee) : 0.0;

        return new OrganizerBillingLineDTO(
            organizerId: $organizer->getId(),
            organizerName: $organizer->getName(),
            soldOrders: (int) $counts->sold_orders,
            soldTickets: (int) $counts->sold_tickets,
            refundedTickets: (int) $counts->refunded_tickets,
            platformFeePerTicket: $platformFee,
            platformFeeTotal: $platformFeeTotal,
            smsEnabled: $smsEnabled,
            smsSent: $smsEnabled ? $smsSent : 0,
            smsSentByType: $smsEnabled ? $smsSentByType : [],
            smsFeePerMessage: $smsFee,
            smsTotal: $smsTotal,
            total: Currency::round($platformFeeTotal + $smsTotal),
            grossSales: $grossSales,
        );
    }

    private function ticketCounts(int $organizerId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): object
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(DISTINCT o.id) AS sold_orders,
                COALESCE(SUM(oi.quantity), 0) AS sold_tickets,
                COALESCE(SUM(CASE WHEN o.refund_status = :refunded THEN oi.quantity ELSE 0 END), 0) AS refunded_tickets
            FROM orders o
            INNER JOIN events e ON e.id = o.event_id
            LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.product_type = 'TICKET'
            WHERE e.organizer_id = :organizer_id
                AND e.deleted_at IS NULL
                AND o.deleted_at IS NULL
                AND o.payment_status = :payment_received
                AND o.created_at BETWEEN :period_start AND :period_end
        SQL;

        return $this->db->selectOne($sql, [
            'refunded' => OrderRefundStatus::REFUNDED->name,
            'organizer_id' => $organizerId,
            'payment_received' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'period_start' => $periodStart->utc()->toDateTimeString(),
            'period_end' => $periodEnd->utc()->toDateTimeString(),
        ]);
    }

    private function grossSales(int $organizerId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $currency): float
    {
        $report = $this->accountingReport->generateReport(
            organizerId: $organizerId,
            currency: $currency,
            startDate: Carbon::instance($periodStart),
            endDate: Carbon::instance($periodEnd),
        );

        return Currency::round(
            $report
                ->where('line_type', AccountingReport::LINE_TYPE_SALE)
                ->sum(fn (object $row) => (float) $row->gross_amount)
        );
    }

    private function platformFee(?OrganizerBillingSettingDomainObject $settings): float
    {
        return $settings !== null
            ? (float) $settings->getPlatformFeePerTicket()
            : (float) $this->config->get('billing.default_platform_fee_per_ticket');
    }

    private function smsFee(?OrganizerBillingSettingDomainObject $settings): float
    {
        return $settings !== null
            ? (float) $settings->getSmsFeePerMessage()
            : (float) $this->config->get('billing.default_sms_fee_per_message');
    }
}
