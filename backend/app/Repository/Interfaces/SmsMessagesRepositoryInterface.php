<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\SmsMessageDomainObject;

/**
 * @extends RepositoryInterface<SmsMessageDomainObject>
 */
interface SmsMessagesRepositoryInterface extends RepositoryInterface
{
    /**
     * @return array<int, int> sent message count keyed by organizer id
     */
    public function countSentPerOrganizerBetween(CarbonInterface $from, CarbonInterface $to): array;
}
