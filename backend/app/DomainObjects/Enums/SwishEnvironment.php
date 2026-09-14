<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SwishEnvironment: string
{
    use BaseEnum;

    case MSS = 'mss';
    case PRODUCTION = 'production';

    public function baseUrl(): string
    {
        return rtrim((string) config('swish.base_urls.'.$this->value), '/');
    }
}
