<?php

namespace HiEvents\Services\Domain\Report\OrganizerReports;

use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Domain\Report\AbstractOrganizerReportService;
use Illuminate\Support\Carbon;

class AccountingReport extends AbstractOrganizerReportService
{
    public const LINE_TYPE_SALE = 'SALE';

    public const LINE_TYPE_REFUND = 'REFUND';

    private const REFUND_SUCCEEDED_STATUS = 'succeeded';

    protected function getSqlQuery(Carbon $startDate, Carbon $endDate, ?string $currency = null): string
    {
        $timezone = addslashes($startDate->getTimezone()->getName());
        $startUtc = $startDate->copy()->utc()->toDateTimeString();
        $endUtc = $endDate->copy()->utc()->toDateTimeString();
        $currencyFilter = $this->buildCurrencyFilter('e.currency', $currency);
        $completed = OrderStatus::COMPLETED->name;
        $sale = self::LINE_TYPE_SALE;
        $refund = self::LINE_TYPE_REFUND;
        $succeeded = self::REFUND_SUCCEEDED_STATUS;

        return <<<SQL
            WITH order_vat AS (
                SELECT
                    o.id AS order_id,
                    COALESCE(SUM(CASE WHEN v.rate_pct = 25 THEN v.amount END), 0) AS vat_25,
                    COALESCE(SUM(CASE WHEN v.rate_pct = 12 THEN v.amount END), 0) AS vat_12,
                    COALESCE(SUM(CASE WHEN v.rate_pct = 6 THEN v.amount END), 0) AS vat_6,
                    COALESCE(SUM(CASE WHEN v.rate_pct NOT IN (25, 12, 6) THEN v.amount END), 0) AS vat_other
                FROM orders o
                INNER JOIN events e ON e.id = o.event_id
                LEFT JOIN LATERAL (
                    SELECT
                        (tax->>'value')::numeric AS amount,
                        ROUND(
                            CASE
                                WHEN (tax->>'rate')::numeric < 1 THEN (tax->>'rate')::numeric * 100
                                ELSE (tax->>'rate')::numeric
                            END
                        ) AS rate_pct
                    FROM jsonb_array_elements(COALESCE(o.taxes_and_fees_rollup->'taxes', '[]'::jsonb)) AS tax
                    WHERE tax->>'rate' IS NOT NULL
                ) v ON TRUE
                WHERE e.organizer_id = :organizer_id
                    AND e.deleted_at IS NULL
                    AND o.deleted_at IS NULL
                    AND o.status = '$completed'
                    $currencyFilter
                GROUP BY o.id
            ),
            sales AS (
                SELECT
                    (o.created_at AT TIME ZONE 'UTC' AT TIME ZONE '$timezone')::date AS date,
                    e.id AS event_id,
                    e.title AS event_name,
                    e.currency,
                    o.payment_provider,
                    '$sale' AS line_type,
                    COUNT(o.id) AS transaction_count,
                    SUM(o.total_gross) AS gross_amount,
                    SUM(o.total_fee) AS service_fee_amount,
                    SUM(ov.vat_25) AS vat_25_amount,
                    SUM(ov.vat_12) AS vat_12_amount,
                    SUM(ov.vat_6) AS vat_6_amount,
                    SUM(ov.vat_other) AS vat_other_amount,
                    SUM(o.total_tax) AS vat_total_amount
                FROM orders o
                INNER JOIN events e ON e.id = o.event_id
                INNER JOIN order_vat ov ON ov.order_id = o.id
                WHERE o.created_at BETWEEN '$startUtc' AND '$endUtc'
                GROUP BY 1, e.id, e.title, e.currency, o.payment_provider
            ),
            refunds AS (
                SELECT
                    (r.created_at AT TIME ZONE 'UTC' AT TIME ZONE '$timezone')::date AS date,
                    e.id AS event_id,
                    e.title AS event_name,
                    e.currency,
                    r.payment_provider,
                    '$refund' AS line_type,
                    COUNT(r.id) AS transaction_count,
                    -SUM(r.amount) AS gross_amount,
                    -SUM(r.amount * o.total_fee / NULLIF(o.total_gross, 0)) AS service_fee_amount,
                    -SUM(r.amount * ov.vat_25 / NULLIF(o.total_gross, 0)) AS vat_25_amount,
                    -SUM(r.amount * ov.vat_12 / NULLIF(o.total_gross, 0)) AS vat_12_amount,
                    -SUM(r.amount * ov.vat_6 / NULLIF(o.total_gross, 0)) AS vat_6_amount,
                    -SUM(r.amount * ov.vat_other / NULLIF(o.total_gross, 0)) AS vat_other_amount,
                    -SUM(r.amount * o.total_tax / NULLIF(o.total_gross, 0)) AS vat_total_amount
                FROM order_refunds r
                INNER JOIN orders o ON o.id = r.order_id
                INNER JOIN events e ON e.id = o.event_id
                INNER JOIN order_vat ov ON ov.order_id = o.id
                WHERE r.deleted_at IS NULL
                    AND r.status = '$succeeded'
                    AND r.created_at BETWEEN '$startUtc' AND '$endUtc'
                GROUP BY 1, e.id, e.title, e.currency, r.payment_provider
            ),
            lines AS (
                SELECT * FROM sales
                UNION ALL
                SELECT * FROM refunds
            )
            SELECT
                l.date,
                l.event_id,
                l.event_name,
                l.currency,
                l.payment_provider,
                l.line_type,
                l.transaction_count,
                ROUND(l.gross_amount::numeric, 2) AS gross_amount,
                ROUND(COALESCE(l.service_fee_amount, 0)::numeric, 2) AS service_fee_amount,
                ROUND(COALESCE(l.vat_25_amount, 0)::numeric, 2) AS vat_25_amount,
                ROUND(COALESCE(l.vat_12_amount, 0)::numeric, 2) AS vat_12_amount,
                ROUND(COALESCE(l.vat_6_amount, 0)::numeric, 2) AS vat_6_amount,
                ROUND(COALESCE(l.vat_other_amount, 0)::numeric, 2) AS vat_other_amount,
                ROUND(COALESCE(l.vat_total_amount, 0)::numeric, 2) AS vat_total_amount,
                ROUND((l.gross_amount - COALESCE(l.vat_total_amount, 0))::numeric, 2) AS net_amount
            FROM lines l
            ORDER BY l.date DESC, l.event_name, l.line_type, l.payment_provider
SQL;
    }
}
