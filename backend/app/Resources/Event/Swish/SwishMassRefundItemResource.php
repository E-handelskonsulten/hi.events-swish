<?php

declare(strict_types=1);

namespace HiEvents\Resources\Event\Swish;

use HiEvents\DomainObjects\SwishMassRefundItemDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SwishMassRefundItemDomainObject
 */
class SwishMassRefundItemResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'order_id' => $this->getOrderId(),
            'order_public_id' => $this->getOrderPublicId(),
            'buyer_name' => $this->getBuyerName(),
            'buyer_email' => $this->getBuyerEmail(),
            'amount' => $this->getAmount(),
            /** @var 'PENDING'|'PROCESSING'|'REQUESTED'|'SUCCEEDED'|'FAILED'|'SKIPPED' */
            'status' => $this->getStatus(),
            'attempts' => $this->getAttempts(),
            'error_code' => $this->getErrorCode(),
            'error_message' => $this->getErrorMessage(),
            'processed_at' => $this->getProcessedAt(),
        ];
    }
}
