<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\SwishRefundStatus;

class SwishRefundDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $status,
        public readonly ?float $amount,
        public readonly ?string $currency,
        public readonly ?string $payerAlias,
        public readonly ?string $payeeAlias,
        public readonly ?string $originalPaymentReference,
        public readonly ?string $paymentReference,
        public readonly ?string $payerPaymentReference,
        public readonly ?string $dateCreated,
        public readonly ?string $datePaid,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $raw = [],
    ) {}

    public static function fromSwishPayload(array $payload): self
    {
        return new self(
            id: strtoupper((string) ($payload['id'] ?? '')),
            status: isset($payload['status']) ? strtoupper((string) $payload['status']) : null,
            amount: isset($payload['amount']) ? (float) $payload['amount'] : null,
            currency: isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
            payerAlias: isset($payload['payerAlias']) ? (string) $payload['payerAlias'] : null,
            payeeAlias: isset($payload['payeeAlias']) ? (string) $payload['payeeAlias'] : null,
            originalPaymentReference: isset($payload['originalPaymentReference']) ? (string) $payload['originalPaymentReference'] : null,
            paymentReference: isset($payload['paymentReference']) ? (string) $payload['paymentReference'] : null,
            payerPaymentReference: isset($payload['payerPaymentReference']) ? (string) $payload['payerPaymentReference'] : null,
            dateCreated: isset($payload['dateCreated']) ? (string) $payload['dateCreated'] : null,
            datePaid: isset($payload['datePaid']) ? (string) $payload['datePaid'] : null,
            errorCode: isset($payload['errorCode']) ? (string) $payload['errorCode'] : null,
            errorMessage: isset($payload['errorMessage']) ? (string) $payload['errorMessage'] : null,
            raw: $payload,
        );
    }

    public function getStatusEnum(): ?SwishRefundStatus
    {
        return SwishRefundStatus::fromSwishStatus($this->status);
    }
}
