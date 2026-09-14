<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund;

use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundRunService;

class RetrySwishMassRefundHandler
{
    public function __construct(
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
        private readonly SwishMassRefundRunService $runService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws ResourceConflictException
     */
    public function handle(int $eventId, int $runId): SwishMassRefundRunDomainObject
    {
        /** @var SwishMassRefundRunDomainObject|null $run */
        $run = $this->runsRepository->findFirstWhere([
            SwishMassRefundRunDomainObjectAbstract::ID => $runId,
            SwishMassRefundRunDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($run === null) {
            throw new ResourceNotFoundException(__('Mass refund not found.'));
        }

        return $this->runService->retryFailed($run);
    }
}
