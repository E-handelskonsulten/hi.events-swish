<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;

class SwishPaymentRequestDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $status,
        public readonly ?float $amount,
        public readonly ?string $currency,
        public readonly ?string $payeeAlias,
        public readonly ?string $payerAlias,
        public readonly ?string $payeePaymentReference,
        public readonly ?string $paymentReference,
        public readonly ?string $message,
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
            payeeAlias: isset($payload['payeeAlias']) ? (string) $payload['payeeAlias'] : null,
            payerAlias: isset($payload['payerAlias']) ? (string) $payload['payerAlias'] : null,
            payeePaymentReference: isset($payload['payeePaymentReference']) ? (string) $payload['payeePaymentReference'] : null,
            paymentReference: isset($payload['paymentReference']) ? (string) $payload['paymentReference'] : null,
            message: isset($payload['message']) ? (string) $payload['message'] : null,
            dateCreated: isset($payload['dateCreated']) ? (string) $payload['dateCreated'] : null,
            datePaid: isset($payload['datePaid']) ? (string) $payload['datePaid'] : null,
            errorCode: isset($payload['errorCode']) ? (string) $payload['errorCode'] : null,
            errorMessage: isset($payload['errorMessage']) ? (string) $payload['errorMessage'] : null,
            raw: $payload,
        );
    }

    public function getStatusEnum(): ?SwishPaymentStatus
    {
        return SwishPaymentStatus::fromSwishStatus($this->status);
    }
}
