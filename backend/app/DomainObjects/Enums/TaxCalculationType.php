<?php

namespace HiEvents\DomainObjects\Enums;

enum TaxCalculationType
{
    use BaseEnum;

    case PERCENTAGE;
    case FIXED;
    case FIXED_PLUS_PERCENTAGE;
}
