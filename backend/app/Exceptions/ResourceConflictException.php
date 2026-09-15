<?php

namespace HiEvents\Exceptions;

use Exception;

class ResourceConflictException extends Exception
{
    public function __construct(
        ?string $message = null,
        int $code = 409,
        ?Exception $previous = null
    ) {
        parent::__construct($message ?? __('Resource conflict'), $code, $previous);
    }
}
