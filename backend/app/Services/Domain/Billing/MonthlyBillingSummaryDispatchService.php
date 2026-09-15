<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Billing;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\BillingSummaryOutcome;
use HiEvents\DomainObjects\Generated\BillingSummaryRunDomainObjectAbstract;
use HiEvents\Mail\Billing\MonthlyBillingSummaryMail;
use HiEvents\Repository\Interfaces\BillingSummaryRunsRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;

class MonthlyBillingSummaryDispatchService
{
    public function __construct(
        private readonly MonthlyBillingSummaryService $summaryService,
        private readonly BillingSummaryCsvBuilder $csvBuilder,
        private readonly BillingSummaryRunsRepositoryInterface $billingSummaryRunsRepository,
        private readonly Mailer $mailer,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(CarbonImmutable $periodStart, bool $force = false, ?string $previewRecipient = null): BillingSummaryOutcome
    {
        $recipient = $previewRecipient ?: $this->config->get('billing.summary_email');

        if (! $recipient) {
            $this->logger->warning('Monthly billing summary skipped: BILLING_SUMMARY_EMAIL is not configured');

            return BillingSummaryOutcome::NO_RECIPIENT;
        }

        $summary = $this->summaryService->build($periodStart);
        $periodKey = $summary->periodStart->toDateString();

        $existingRun = $previewRecipient
            ? null
            : $this->billingSummaryRunsRepository->findFirstWhere([
                BillingSummaryRunDomainObjectAbstract::PERIOD_START => $periodKey,
            ]);

        if ($existingRun !== null && ! $force) {
            $this->logger->info('Monthly billing summary already sent', ['period' => $periodKey]);

            return BillingSummaryOutcome::ALREADY_SENT;
        }

        $this->mailer->to($recipient)->send(new MonthlyBillingSummaryMail($summary, $this->csvBuilder->build($summary)));

        if ($previewRecipient === null) {
            $data = [
                BillingSummaryRunDomainObjectAbstract::PERIOD_START => $periodKey,
                BillingSummaryRunDomainObjectAbstract::RECIPIENT => $recipient,
                BillingSummaryRunDomainObjectAbstract::ORGANIZER_COUNT => $summary->lines->count(),
                BillingSummaryRunDomainObjectAbstract::GRAND_TOTAL => $summary->grandTotal,
                BillingSummaryRunDomainObjectAbstract::SENT_AT => now()->toDateTimeString(),
            ];

            $existingRun !== null
                ? $this->billingSummaryRunsRepository->updateFromArray($existingRun->getId(), $data)
                : $this->billingSummaryRunsRepository->create($data);
        }

        $this->logger->info('Monthly billing summary sent', [
            'period' => $periodKey,
            'recipient' => $recipient,
            'organizers' => $summary->lines->count(),
            'grand_total' => $summary->grandTotal,
            'preview' => $previewRecipient !== null,
        ]);

        return BillingSummaryOutcome::SENT;
    }
}
