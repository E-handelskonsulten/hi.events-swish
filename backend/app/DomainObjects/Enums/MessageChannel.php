<?php

namespace HiEvents\DomainObjects\Enums;

enum MessageChannel
{
    use BaseEnum;

    case EMAIL;
    case SMS;
    case BOTH;

    public function includesEmail(): bool
    {
        return $this !== self::SMS;
    }

    public function includesSms(): bool
    {
        return $this !== self::EMAIL;
    }
}
