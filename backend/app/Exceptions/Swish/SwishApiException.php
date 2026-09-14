<?php

declare(strict_types=1);

namespace HiEvents\Exceptions\Swish;

use HiEvents\Exceptions\BaseException;
use Throwable;

class SwishApiException extends BaseException
{
    /**
     * @param  array<int, array{errorCode?: string, errorMessage?: string, additionalInformation?: string}>  $errors
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    public function getErrorCodes(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $error) => $error['errorCode'] ?? null,
            $this->errors,
        )));
    }

    public function hasErrorCode(string $code): bool
    {
        return in_array($code, $this->getErrorCodes(), true);
    }

    public function getFirstErrorCode(): ?string
    {
        return $this->getErrorCodes()[0] ?? null;
    }
}
