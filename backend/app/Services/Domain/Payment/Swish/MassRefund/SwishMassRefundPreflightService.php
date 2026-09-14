<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\SwishMassRefundManualReason;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundOrderDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundPreviewDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundTicketTypeDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class SwishMassRefundPreflightService
{
    public const MINIMUM_REFUND_AMOUNT = 1.0;

    public const SWISH_REFUND_WINDOW_MONTHS = 12;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
    ) {}

    public function preview(EventDomainObject $event): SwishMassRefundPreviewDTO
    {
        $candidates = $this->fetchCandidates($event->getId());

        $refundable = [];
        $manual = [];

        foreach ($candidates as $row) {
            $amount = round((float) $row->total_gross - (float) $row->total_refunded, 2);
            $reason = $this->manualReason($row);

            $dto = new SwishMassRefundOrderDTO(
                order_id: (int) $row->id,
                public_id: (string) $row->public_id,
                buyer_name: trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: null,
                buyer_email: $row->email,
                amount: $amount,
                reason: $reason?->value,
                reason_label: $reason?->label(),
            );

            if ($reason === null) {
                $refundable[] = $dto;
            } else {
                $manual[] = $dto;
            }
        }

        $refundableIds = array_map(fn (SwishMassRefundOrderDTO $dto) => $dto->order_id, $refundable);

        return new SwishMassRefundPreviewDTO(
            event_id: $event->getId(),
            event_title: $event->getTitle(),
            currency: $event->getCurrency(),
            refundable_count: count($refundable),
            total_amount: round(array_sum(array_map(fn (SwishMassRefundOrderDTO $dto) => $dto->amount, $refundable)), 2),
            manual_count: count($manual),
            manual_amount: round(array_sum(array_map(fn (SwishMassRefundOrderDTO $dto) => $dto->amount, $manual)), 2),
            ticket_types: $this->ticketTypeBreakdown($refundableIds),
            refundable_orders: $refundable,
            manual_orders: $manual,
            active_run_id: $this->findActiveRunId($event->getId()),
        );
    }

    public function findActiveRunId(int $eventId): ?int
    {
        $run = $this->runsRepository->findWhereIn(
            field: SwishMassRefundRunDomainObjectAbstract::STATUS,
            values: [SwishMassRefundRunStatus::PENDING->value, SwishMassRefundRunStatus::RUNNING->value],
            additionalWhere: [SwishMassRefundRunDomainObjectAbstract::EVENT_ID => $eventId],
        )->first();

        return $run?->getId();
    }

    private function fetchCandidates(int $eventId): Collection
    {
        $sql = <<<'SQL'
            SELECT
                o.id,
                o.public_id,
                o.email,
                o.first_name,
                o.last_name,
                o.total_gross,
                o.total_refunded,
                o.refund_status,
                p.payment_reference,
                p.date_paid,
                EXISTS (
                    SELECT 1 FROM swish_refunds r
                    WHERE r.order_id = o.id AND r.status = :refund_error_status AND r.deleted_at IS NULL
                ) AS has_failed_refund
            FROM orders o
            LEFT JOIN LATERAL (
                SELECT sp.payment_reference, sp.date_paid
                FROM swish_payments sp
                WHERE sp.order_id = o.id AND sp.status = :paid_status AND sp.deleted_at IS NULL
                ORDER BY sp.id DESC
                LIMIT 1
            ) p ON TRUE
            WHERE o.event_id = :event_id
                AND o.deleted_at IS NULL
                AND o.payment_provider = :provider
                AND o.payment_status = :payment_status
                AND (o.refund_status IS NULL OR o.refund_status <> :refunded_status)
                AND (o.total_gross - o.total_refunded) >= :minimum_amount
            ORDER BY o.id
        SQL;

        return collect($this->databaseManager->select($sql, [
            'refund_error_status' => SwishRefundStatus::ERROR->value,
            'paid_status' => SwishPaymentStatus::PAID->value,
            'event_id' => $eventId,
            'provider' => PaymentProviders::SWISH->value,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'refunded_status' => OrderRefundStatus::REFUNDED->name,
            'minimum_amount' => self::MINIMUM_REFUND_AMOUNT,
        ]));
    }

    private function manualReason(object $row): ?SwishMassRefundManualReason
    {
        if ($row->refund_status === OrderRefundStatus::REFUND_PENDING->name) {
            return SwishMassRefundManualReason::REFUND_PENDING;
        }

        if ($row->refund_status === OrderRefundStatus::REFUND_FAILED->name || $row->has_failed_refund) {
            return SwishMassRefundManualReason::PREVIOUS_REFUND_FAILED;
        }

        if ($row->payment_reference === null || $row->payment_reference === '') {
            return SwishMassRefundManualReason::NO_SWISH_PAYMENT_REFERENCE;
        }

        if ($row->date_paid !== null && now()->parse($row->date_paid)->lt(now()->subMonths(self::SWISH_REFUND_WINDOW_MONTHS))) {
            return SwishMassRefundManualReason::PAYMENT_OLDER_THAN_12_MONTHS;
        }

        return null;
    }

    /**
     * @param  int[]  $orderIds
     * @return SwishMassRefundTicketTypeDTO[]
     */
    private function ticketTypeBreakdown(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->databaseManager->table('order_items')
            ->selectRaw('item_name, SUM(quantity) AS quantity, COUNT(DISTINCT order_id) AS order_count, SUM(total_gross) AS amount')
            ->whereIn('order_id', $orderIds)
            ->whereNull('deleted_at')
            ->groupBy('item_name')
            ->orderByRaw('SUM(total_gross) DESC')
            ->get();

        return $rows->map(fn (object $row) => new SwishMassRefundTicketTypeDTO(
            name: (string) $row->item_name,
            quantity: (int) $row->quantity,
            order_count: (int) $row->order_count,
            amount: round((float) $row->amount, 2),
        ))->all();
    }
}
