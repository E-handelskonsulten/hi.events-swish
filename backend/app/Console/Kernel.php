<?php

namespace HiEvents\Console;

use HiEvents\Jobs\Account\ProcessScheduledAccountDeletionsJob;
use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use HiEvents\Jobs\Order\Swish\ProcessActiveSwishMassRefundRunsJob;
use HiEvents\Jobs\Order\Swish\ReconcilePendingSwishPaymentsJob;
use HiEvents\Jobs\Order\Swish\ReconcilePendingSwishRefundsJob;
use HiEvents\Jobs\Order\Swish\ResumeStalledSwishMassRefundRunsJob;
use HiEvents\Jobs\Sms\ProcessDueSmsMessagesJob;
use HiEvents\Jobs\Waitlist\ProcessExpiredWaitlistOffersJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessExpiredWaitlistOffersJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessScheduledAccountDeletionsJob)->hourly()->withoutOverlapping();
        $schedule->job(new ReconcilePendingSwishPaymentsJob)->everyFifteenSeconds()->withoutOverlapping();
        $schedule->job(new ReconcilePendingSwishRefundsJob)->everyThirtySeconds()->withoutOverlapping();
        $schedule->job(new ProcessActiveSwishMassRefundRunsJob)->everyFiveSeconds()->withoutOverlapping();
        $schedule->job(new ResumeStalledSwishMassRefundRunsJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessDueSmsMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->command('billing:send-monthly-summary')
            ->monthlyOn(1, '07:00')
            ->timezone(config('billing.timezone'))
            ->withoutOverlapping();

        $schedule->call(function (): void {
            $count = DB::table('failed_jobs')->count();
            if ($count > 0) {
                Log::warning('Failed jobs present in queue', ['count' => $count]);
            }
        })->everyFiveMinutes()->name('failed-jobs-monitor')->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        include base_path('routes/console.php');
    }
}
