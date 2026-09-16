<?php

namespace HiEvents\DomainObjects\Enums;

enum MessagePurpose
{
    use BaseEnum;

    case SERVICE;
    case MARKETING;
}
