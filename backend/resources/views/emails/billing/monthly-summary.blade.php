@php /** @var \HiEvents\Services\Domain\Billing\DTO\MonthlyBillingSummaryDTO $summary */ @endphp

@php /** @see \HiEvents\Mail\Billing\MonthlyBillingSummaryMail */ @endphp

@php use HiEvents\Helper\Currency; @endphp
@php $money = fn (float $amount) => Currency::format($amount, $summary->currency, 'sv_SE'); @endphp

<x-mail::message>
# {{ __('Billing basis :month', ['month' => $summary->monthLabel()]) }}

{{ __('Period') }}: {{ $summary->periodStart->format('Y-m-d') }} – {{ $summary->periodEnd->format('Y-m-d') }} ({{ $summary->periodStart->getTimezone()->getName() }})

@if ($summary->isEmpty())
{{ __('No tickets were sold and no SMS were sent during this period. There is nothing to invoice.') }}
@else
@foreach ($summary->lines as $line)
## {{ $line->organizerName }}

<x-mail::table>
| {{ __('Item') }} | {{ __('Quantity × price') }} | {{ __('Amount') }} |
|:--|--:|--:|
| {{ __('Sold tickets') }} | {{ $line->soldTickets }} × {{ $money($line->platformFeePerTicket) }} | {{ $money($line->platformFeeTotal) }} |
@if ($line->refundedTickets > 0)
| {{ __('of which :count refunded — does not affect the fee', ['count' => $line->refundedTickets]) }} | | |
@endif
@if ($line->smsEnabled)
| {{ __('SMS sent') }} | {{ $line->smsSent }} × {{ $money($line->smsFeePerMessage) }} | {{ $money($line->smsTotal) }} |
@endif
| **{{ __('Organizer total') }}** | | **{{ $money($line->total) }}** |
</x-mail::table>

{{ __('Gross ticket sales (not billable)') }}: {{ $money($line->grossSales) }} · {{ __(':count orders', ['count' => $line->soldOrders]) }}

@endforeach
## {{ __('Total to invoice') }}: {{ $money($summary->grandTotal) }}

{{ __('The full basis is attached as CSV.') }}
@endif

<small>{{ __('Fee per sold ticket, refunds do not affect it. SMS are charged per sent message.') }}</small>
</x-mail::message>
