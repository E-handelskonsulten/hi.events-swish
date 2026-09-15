@php /** @var \HiEvents\DomainObjects\SwishMassRefundRunDomainObject $run */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\DomainObjects\SwishMassRefundItemDomainObject> $failedItems */ @endphp
@php /** @var array<int, array<string, mixed>> $manualOrders */ @endphp

@php /** @see \HiEvents\Mail\Swish\SwishMassRefundCompletedMail */ @endphp

@php use HiEvents\Helper\Currency; @endphp
@php $money = fn ($amount) => Currency::format((float) $amount, $run->getCurrency()); @endphp

<x-mail::message>
{{ __('Hello') }},

<p>
{{ __('The mass refund for :eventTitle started by :initiator has finished.', [
    'eventTitle' => $event->getTitle(),
    'initiator' => $run->getInitiatedByName() ?: __('an administrator'),
]) }}
</p>

<p>
{{ __('Refunded') }}: <strong>{{ $run->getSucceededCount() }} / {{ $run->getTotalOrders() }}</strong> {{ __('orders') }}, <strong>{{ $money($run->getSucceededAmount()) }}</strong> {{ __('of') }} {{ $money($run->getTotalAmount()) }}<br>
{{ __('Failed') }}: {{ $run->getFailedCount() }}<br>
{{ __('Skipped (already refunded)') }}: {{ $run->getSkippedCount() }}<br>
{{ __('Requires manual handling') }}: {{ $run->getManualCount() }}<br>
{{ __('Started') }}: {{ $run->getStartedAt() ?: $run->getCreatedAt() }}<br>
{{ __('Completed') }}: {{ $run->getCompletedAt() }}
</p>

@if ($failedItems->isNotEmpty())
<p><strong>{{ __('Failed refunds') }}</strong></p>
<x-mail::table>
| {{ __('Order') }} | {{ __('Buyer') }} | {{ __('Amount') }} | {{ __('Error') }} |
|:--|:--|--:|:--|
@foreach ($failedItems as $item)
| {{ $item->getOrderPublicId() }} | {{ $item->getBuyerEmail() ?: '-' }} | {{ $money($item->getAmount()) }} | {{ $item->getErrorCode() ? $item->getErrorCode().' ' : '' }}{{ $item->getErrorMessage() ?: '-' }} |
@endforeach
</x-mail::table>
<p>{{ __('Failed refunds can be retried from the mass refund progress view in the admin panel.') }}</p>
@endif

@if (count($manualOrders) > 0)
<p><strong>{{ __('Orders that must be refunded manually') }}</strong></p>
<x-mail::table>
| {{ __('Order') }} | {{ __('Buyer') }} | {{ __('Amount') }} | {{ __('Reason') }} |
|:--|:--|--:|:--|
@foreach ($manualOrders as $order)
| {{ $order['public_id'] ?? '-' }} | {{ $order['buyer_email'] ?? '-' }} | {{ $money($order['amount'] ?? 0) }} | {{ $order['reason_label'] ?? ($order['reason'] ?? '-') }} |
@endforeach
</x-mail::table>
@endif

{{ __('Best regards') }},<br>
{{ config('app.name') }}
</x-mail::message>
