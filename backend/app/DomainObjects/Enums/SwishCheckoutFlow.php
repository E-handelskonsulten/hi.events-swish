<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SwishCheckoutFlow: string
{
    use BaseEnum;

    case MCOMMERCE = 'MCOMMERCE';
    case ECOMMERCE = 'ECOMMERCE';
}
