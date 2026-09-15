<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SmsMessageDomainObject>
 */
class SmsMessagesRepository extends BaseRepository implements SmsMessagesRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsMessageDomainObject::class;
    }

    public function countSentPerOrganizerBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->runQuery(fn () => $this->model
            ->query()
            ->selectRaw('organizer_id, count(*) as sent_count')
            ->where('status', SmsMessageStatus::SENT->value)
            ->where('sent_at', '>=', $from)
            ->where('sent_at', '<', $to)
            ->groupBy('organizer_id')
            ->pluck('sent_count', 'organizer_id')
            ->map(static fn ($count) => (int) $count)
            ->all());
    }

    public function findDueScheduled(CarbonInterface $now): Collection
    {
        return $this->runQuery(fn () => $this->handleResults($this->model
            ->where('status', SmsMessageStatus::SCHEDULED->value)
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')
            ->limit(200)
            ->get()));
    }
}
