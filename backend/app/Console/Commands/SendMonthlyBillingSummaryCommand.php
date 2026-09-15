<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\BillingSummaryOutcome;
use HiEvents\Jobs\Billing\SendMonthlyBillingSummaryJob;
use HiEvents\Services\Domain\Billing\MonthlyBillingSummaryDispatchService;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

class SendMonthlyBillingSummaryCommand extends Command
{
    protected $signature = 'billing:send-monthly-summary
        {--month= : Month to summarise as YYYY-MM (defaults to the previous month)}
        {--force : Send again even if this month has already been sent}
        {--to= : Send a preview to this address instead of BILLING_SUMMARY_EMAIL, without recording the run}
        {--sync : Generate and send immediately instead of queueing the job}';

    protected $description = 'Email the internal invoicing basis (platform and SMS fees per organizer) for a calendar month';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly MonthlyBillingSummaryDispatchService $dispatchService,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $timezone = $this->config->get('billing.timezone');
        $month = $this->option('month');

        $periodStart = $month
            ? CarbonImmutable::createFromFormat('Y-m', $month, $timezone)?->startOfMonth()
            : CarbonImmutable::now($timezone)->subMonthNoOverflow()->startOfMonth();

        if ($periodStart === null || ($month && $periodStart->format('Y-m') !== $month)) {
            $this->error('Use --month=YYYY-MM.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $preview = $this->option('to') ?: null;

        if (! $this->option('sync')) {
            $this->bus->dispatch(new SendMonthlyBillingSummaryJob($periodStart->toDateString(), $force, $preview));
            $this->info("Queued billing summary for {$periodStart->format('Y-m')}.");

            return self::SUCCESS;
        }

        $outcome = $this->dispatchService->send($periodStart, $force, $preview);

        match ($outcome) {
            BillingSummaryOutcome::SENT => $this->info("Billing summary for {$periodStart->format('Y-m')} sent to ".($preview ?: $this->config->get('billing.summary_email')).'.'),
            BillingSummaryOutcome::ALREADY_SENT => $this->warn("Billing summary for {$periodStart->format('Y-m')} was already sent. Use --force to send it again."),
            BillingSummaryOutcome::NO_RECIPIENT => $this->error('BILLING_SUMMARY_EMAIL is not configured.'),
        };

        return $outcome === BillingSummaryOutcome::NO_RECIPIENT ? self::FAILURE : self::SUCCESS;
    }
}
