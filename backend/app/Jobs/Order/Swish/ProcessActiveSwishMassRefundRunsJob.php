<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessActiveSwishMassRefundRunsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(SwishMassRefundRunsRepositoryInterface $runsRepository): void
    {
        $active = $runsRepository->findWhereIn(
            field: SwishMassRefundRunDomainObjectAbstract::STATUS,
            values: [SwishMassRefundRunStatus::PENDING->value, SwishMassRefundRunStatus::RUNNING->value],
        );

        /** @var SwishMassRefundRunDomainObject $run */
        foreach ($active as $run) {
            ProcessSwishMassRefundRunJob::dispatch($run->getId());
        }
    }
}
