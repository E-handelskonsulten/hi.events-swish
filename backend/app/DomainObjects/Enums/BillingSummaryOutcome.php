<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum BillingSummaryOutcome: string
{
    case SENT = 'sent';
    case ALREADY_SENT = 'already_sent';
    case NO_RECIPIENT = 'no_recipient';
}
