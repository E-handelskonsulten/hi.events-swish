<?php

declare(strict_types=1);

namespace HiEvents\Resources\Event\Swish;

use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SwishMassRefundRunDomainObject
 */
class SwishMassRefundRunResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEventId(),
            /** @var 'PENDING'|'RUNNING'|'COMPLETED' */
            'status' => $this->getStatus(),
            'currency' => $this->getCurrency(),
            'initiated_by_user_id' => $this->getInitiatedByUserId(),
            'initiated_by_name' => $this->getInitiatedByName(),
            'total_orders' => $this->getTotalOrders(),
            'total_amount' => $this->getTotalAmount(),
            'manual_count' => $this->getManualCount(),
            'pending_count' => $this->getPendingCount(),
            'requested_count' => $this->getRequestedCount(),
            'succeeded_count' => $this->getSucceededCount(),
            'failed_count' => $this->getFailedCount(),
            'skipped_count' => $this->getSkippedCount(),
            'succeeded_amount' => $this->getSucceededAmount(),
            'notify_buyers' => (bool) $this->getNotifyBuyers(),
            'cancel_orders' => (bool) $this->getCancelOrders(),
            'summary' => $this->getSummary(),
            'started_at' => $this->getStartedAt(),
            'completed_at' => $this->getCompletedAt(),
            'last_activity_at' => $this->getLastActivityAt(),
            'created_at' => $this->getCreatedAt(),
            'items' => $this->when(
                $this->getItems() !== null,
                fn () => SwishMassRefundItemResource::collection($this->getItems()),
            ),
        ];
    }
}
