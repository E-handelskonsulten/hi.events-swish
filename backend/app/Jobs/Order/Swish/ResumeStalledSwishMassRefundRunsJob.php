<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

class ResumeStalledSwishMassRefundRunsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(
        SwishMassRefundRunsRepositoryInterface $runsRepository,
        SwishMassRefundProcessor $processor,
        Repository $config,
        LoggerInterface $logger,
    ): void {
        $staleAfter = max(30, (int) $config->get('swish.mass_refund.stale_after_seconds', 180));
        $threshold = now()->subSeconds($staleAfter);

        $active = $runsRepository->findWhereIn(
            field: SwishMassRefundRunDomainObjectAbstract::STATUS,
            values: [SwishMassRefundRunStatus::PENDING->value, SwishMassRefundRunStatus::RUNNING->value],
        );

        /** @var SwishMassRefundRunDomainObject $run */
        foreach ($active as $run) {
            $lastActivity = $run->getLastActivityAt() ?? $run->getCreatedAt();

            if ($lastActivity !== null && Carbon::parse($lastActivity)->gt($threshold)) {
                continue;
            }

            $released = $processor->releaseStaleProcessingItems($run->getId());

            $logger->warning('Released stalled Swish mass refund items back to the queue', [
                'run_id' => $run->getId(),
                'event_id' => $run->getEventId(),
                'last_activity_at' => $lastActivity,
                'released_items' => $released,
            ]);

            $runsRepository->updateFromArray($run->getId(), [
                SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
            ]);
        }
    }
}
