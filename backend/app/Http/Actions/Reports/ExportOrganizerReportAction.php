<?php

namespace HiEvents\Http\Actions\Reports;

use HiEvents\DomainObjects\Enums\OrganizerReportTypes;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Report\GetOrganizerReportRequest;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetOrganizerReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetOrganizerReportHandler;
use HiEvents\Services\Domain\Report\DTO\PaginatedReportDTO;
use HiEvents\Services\Infrastructure\Export\SpreadsheetFormulaEscaper;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ExportOrganizerReportAction extends BaseAction
{
    private const MAX_EXPORT_ROWS = 15000;

    public function __construct(
        private readonly GetOrganizerReportHandler $reportHandler,
        private readonly SpreadsheetFormulaEscaper $formulaEscaper,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(GetOrganizerReportRequest $request, int $organizerId, string $reportType): StreamedResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $this->validateDateRange($request);

        if (! in_array($reportType, OrganizerReportTypes::valuesArray(), true)) {
            throw new BadRequestHttpException(__('Invalid report type.'));
        }

        $reportData = $this->reportHandler->handle(
            reportData: new GetOrganizerReportDTO(
                organizerId: $organizerId,
                reportType: OrganizerReportTypes::from($reportType),
                startDate: $request->validated('start_date'),
                endDate: $request->validated('end_date'),
                currency: $request->validated('currency'),
                eventId: $request->validated('event_id'),
                page: 1,
                perPage: self::MAX_EXPORT_ROWS,
            ),
        );

        $data = $reportData instanceof PaginatedReportDTO
            ? $reportData->data
            : $reportData;

        $filename = $reportType.'_'.date('Y-m-d_H-i-s').'.csv';

        return new StreamedResponse(function () use ($data, $reportType) {
            $handle = fopen('php://output', 'w');

            $headers = $this->getHeadersForReportType($reportType);
            fputcsv($handle, $headers);

            foreach ($data as $row) {
                $csvRow = $this->formatRowForReportType($row, $reportType);
                fputcsv($handle, $this->formulaEscaper->escapeRow($csvRow));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function humanize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return __(ucfirst(strtolower($value)));
    }

    private function getHeadersForReportType(string $reportType): array
    {
        return match ($reportType) {
            OrganizerReportTypes::PLATFORM_FEES->value => [
                __('Event'),
                __('Payment Date'),
                __('Order Reference'),
                __('Amount Paid'),
                __('Hi.Events Fee'),
                __('VAT Rate'),
                __('VAT on Fee'),
                __('Total Fee'),
                __('Currency'),
                __('Stripe Payment ID'),
            ],
            OrganizerReportTypes::REVENUE_SUMMARY->value => [
                __('Date'),
                __('Gross Sales'),
                __('Net Revenue'),
                __('Total Refunded'),
                __('Total Tax'),
                __('Total Fee'),
                __('Order Count'),
            ],
            OrganizerReportTypes::EVENTS_PERFORMANCE->value => [
                __('Event ID'),
                __('Event Name'),
                __('Currency'),
                __('Start Date'),
                __('End Date'),
                __('Status'),
                __('Event State'),
                __('Products Sold'),
                __('Gross Revenue'),
                __('Total Refunded'),
                __('Net Revenue'),
                __('Total Tax'),
                __('Total Fee'),
                __('Total Orders'),
                __('Unique Customers'),
                __('Page Views'),
            ],
            OrganizerReportTypes::TAX_SUMMARY->value => [
                __('Event ID'),
                __('Event Name'),
                __('Currency'),
                __('Tax Name'),
                __('Tax Rate'),
                __('Total Collected'),
                __('Order Count'),
            ],
            OrganizerReportTypes::CHECK_IN_SUMMARY->value => [
                __('Event ID'),
                __('Event Name'),
                __('Start Date'),
                __('Total Attendees'),
                __('Total Checked In'),
                __('Check-in Rate (%)'),
                __('Check-in Lists Count'),
            ],
            OrganizerReportTypes::ACCOUNTING->value => [
                __('Date'),
                __('Event ID'),
                __('Event Name'),
                __('Payment Provider'),
                __('Line Type'),
                __('Transactions'),
                __('Gross'),
                __('Service Fees'),
                __('VAT 25%'),
                __('VAT 12%'),
                __('VAT 6%'),
                __('VAT Other'),
                __('VAT Total'),
                __('Net (excl. VAT)'),
                __('Currency'),
            ],
            default => [],
        };
    }

    private function formatRowForReportType(object $row, string $reportType): array
    {
        return match ($reportType) {
            OrganizerReportTypes::PLATFORM_FEES->value => [
                $row->event_name ?? '',
                $row->payment_date ? date('Y-m-d H:i:s', strtotime($row->payment_date)) : '',
                $row->order_reference ?? '',
                $row->amount_paid ?? 0,
                $row->fee_amount ?? 0,
                $row->vat_rate !== null ? ($row->vat_rate * 100).'%' : '',
                $row->vat_amount ?? 0,
                $row->total_fee ?? 0,
                $row->currency ?? '',
                $row->payment_intent_id ?? '',
            ],
            OrganizerReportTypes::REVENUE_SUMMARY->value => [
                $row->date ?? '',
                $row->gross_sales ?? 0,
                $row->net_revenue ?? 0,
                $row->total_refunded ?? 0,
                $row->total_tax ?? 0,
                $row->total_fee ?? 0,
                $row->order_count ?? 0,
            ],
            OrganizerReportTypes::EVENTS_PERFORMANCE->value => [
                $row->event_id ?? '',
                $row->event_name ?? '',
                $row->event_currency ?? '',
                $row->start_date ?? '',
                $row->end_date ?? '',
                $row->status ?? '',
                $row->event_state ?? '',
                $row->products_sold ?? 0,
                $row->gross_revenue ?? 0,
                $row->total_refunded ?? 0,
                $row->net_revenue ?? 0,
                $row->total_tax ?? 0,
                $row->total_fee ?? 0,
                $row->total_orders ?? 0,
                $row->unique_customers ?? 0,
                $row->page_views ?? 0,
            ],
            OrganizerReportTypes::TAX_SUMMARY->value => [
                $row->event_id ?? '',
                $row->event_name ?? '',
                $row->event_currency ?? '',
                $row->tax_name ?? '',
                $row->tax_rate ? ($row->tax_rate * 100).'%' : '',
                $row->total_collected ?? 0,
                $row->order_count ?? 0,
            ],
            OrganizerReportTypes::CHECK_IN_SUMMARY->value => [
                $row->event_id ?? '',
                $row->event_name ?? '',
                $row->start_date ?? '',
                $row->total_attendees ?? 0,
                $row->total_checked_in ?? 0,
                $row->check_in_rate ?? 0,
                $row->check_in_lists_count ?? 0,
            ],
            OrganizerReportTypes::ACCOUNTING->value => [
                $row->date ?? '',
                $row->event_id ?? '',
                $row->event_name ?? '',
                $this->humanize($row->payment_provider ?? ''),
                $this->humanize($row->line_type ?? ''),
                $row->transaction_count ?? 0,
                $row->gross_amount ?? 0,
                $row->service_fee_amount ?? 0,
                $row->vat_25_amount ?? 0,
                $row->vat_12_amount ?? 0,
                $row->vat_6_amount ?? 0,
                $row->vat_other_amount ?? 0,
                $row->vat_total_amount ?? 0,
                $row->net_amount ?? 0,
                $row->currency ?? '',
            ],
            default => [],
        };
    }

    /**
     * @throws ValidationException
     */
    private function validateDateRange(GetOrganizerReportRequest $request): void
    {
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');

        if (! $startDate || ! $endDate) {
            return;
        }

        $diffInDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate));

        if ($diffInDays > 370) {
            throw ValidationException::withMessages(['start_date' => __('Date range must be less than 370 days.')]);
        }
    }
}
