<?php

declare(strict_types=1);

namespace HiEvents\Resources\Order\Swish;

use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Payment\Swish\SwishPayerAliasNormalizer;
use Illuminate\Http\Request;

/**
 * @mixin SwishPaymentDomainObject
 */
class SwishPaymentResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'instruction_uuid' => $this->getInstructionUuid(),
            /** @var 'CREATED'|'PAID'|'DECLINED'|'ERROR'|'CANCELLED'|'EXPIRED'|'PAID_FLAGGED' */
            'status' => $this->getStatus(),
            /** @var 'MCOMMERCE'|'ECOMMERCE' */
            'flow' => $this->getFlow(),
            'is_terminal' => $this->isTerminal(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'payment_request_token' => $this->when(
                $this->getStatus() === SwishPaymentStatus::CREATED->value,
                fn () => $this->getPaymentRequestToken(),
            ),
            'payer_alias_masked' => SwishPayerAliasNormalizer::mask($this->getPayerAlias()),
            'error_code' => $this->getErrorCode(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'order' => $this->when(
                $this->getOrder() !== null,
                fn () => [
                    'short_id' => $this->getOrder()->getShortId(),
                    /** @var 'RESERVED'|'CANCELLED'|'COMPLETED'|'AWAITING_OFFLINE_PAYMENT'|'ABANDONED' */
                    'status' => $this->getOrder()->getStatus(),
                    /** @var 'NO_PAYMENT_REQUIRED'|'AWAITING_PAYMENT'|'AWAITING_OFFLINE_PAYMENT'|'PAYMENT_FAILED'|'PAYMENT_RECEIVED'|null */
                    'payment_status' => $this->getOrder()->getPaymentStatus(),
                    'reserved_until' => $this->getOrder()->getReservedUntil(),
                ],
            ),
        ];
    }
}
