@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\Values\MoneyValue $refundAmount */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp

@php /** @see \HiEvents\Mail\Order\OrderRefunded */ @endphp
@php use HiEvents\Helper\Currency; @endphp

<x-mail::message :organizer="$organizer">
{{ __('Hello') }},

{{ __('You have received a refund of :refundAmount for the following event: :eventTitle.', ['refundAmount' => Currency::format($refundAmount->toFloat(), $refundAmount->getMoney()->getCurrency()->getCurrencyCode()), 'eventTitle' => $event->getTitle()]) }}

{{ __('Thank you') }},<br>
{{ $organizer->getName() ?: config('app.name') }}

{!! $eventSettings->getGetEmailFooterHtml() !!}
</x-mail::message>
