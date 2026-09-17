<?php

namespace HiEvents\DomainObjects\Enums;

enum PriceDisplayMode
{
    use BaseEnum;

    case INCLUSIVE;
    case EXCLUSIVE;

    /** Ticket price only on the event page; fees and tax appear as their own lines in checkout. */
    case CHECKOUT;
}
