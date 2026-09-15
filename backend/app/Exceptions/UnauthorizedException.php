<?php

namespace HiEvents\Exceptions;

use HiEvents\Http\ResponseCodes;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class UnauthorizedException extends AccessDeniedHttpException
{
    public function __construct(
        ?string $message = null,
        ?Throwable $previous = null,
        int $code = ResponseCodes::HTTP_FORBIDDEN,
        array $headers = []
    ) {
        parent::__construct($message ?? __('This action is unauthorized'), $previous, $code, $headers);
    }
}
