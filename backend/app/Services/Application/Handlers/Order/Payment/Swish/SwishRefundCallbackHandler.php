<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\SwishRefundStatusReconciliationService;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishRefundDTO;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishRefundCallbackHandler
{
    public function __construct(
        private readonly SwishRefundsRepositoryInterface $swishRefundsRepository,
        private readonly SwishRefundStatusReconciliationService $reconciliationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(array $payload): void
    {
        $remote = SwishRefundDTO::fromSwishPayload($payload);

        if ($remote->id === '') {
            $this->logger->warning('Swish refund callback received without an id', ['payload' => $payload]);

            return;
        }

        /** @var SwishRefundDomainObject|null $refund */
        $refund = $this->swishRefundsRepository->findFirstWhere([
            SwishRefundDomainObjectAbstract::INSTRUCTION_UUID => $remote->id,
        ]);

        if ($refund === null) {
            $this->logger->warning('Swish refund callback received for unknown refund', [
                'instruction_uuid' => $remote->id,
                'remote_status' => $remote->status,
            ]);

            return;
        }

        $logContext = [
            'swish_refund_id' => $refund->getId(),
            'instruction_uuid' => $refund->getInstructionUuid(),
            'order_id' => $refund->getOrderId(),
            'local_status' => $refund->getStatus(),
            'remote_status' => $remote->status,
        ];

        if ($refund->isTerminal()) {
            $this->logger->info('Swish refund callback ignored, refund already in terminal state', $logContext);

            return;
        }

        $this->swishRefundsRepository->updateFromArray($refund->getId(), [
            SwishRefundDomainObjectAbstract::CALLBACK_PAYLOAD => $payload,
        ]);

        if (! $this->reconciliationService->matchesLocalRecord($refund, $remote)) {
            $this->logger->error('Swish refund callback payload does not match local record', $logContext + [
                'remote_amount' => $remote->amount,
                'local_amount' => $refund->getAmount(),
            ]);
        }

        $this->logger->info('Processing Swish refund callback', $logContext);

        $this->reconciliationService->reconcile($refund);
    }
}
