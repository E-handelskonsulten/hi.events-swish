@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\SwishPaymentDomainObject $payment */ @endphp
@php /** @var string $reason */ @endphp

@php /** @see \HiEvents\Mail\Swish\SwishPaymentNeedsReviewMail */ @endphp

<x-mail::message>
{{ __('Hello') }},

<p>
{{ __('A Swish payment of :amount was received for order :order (:eventTitle), but the order could not be completed automatically.', [
    'amount' => \HiEvents\Helper\Currency::format((float) $payment->getAmount(), $payment->getCurrency()),
    'order' => $order->getPublicId(),
    'eventTitle' => $event->getTitle(),
]) }}
</p>

<p>
{{ __('Reason') }}: <strong>{{ $reason }}</strong><br>
{{ __('Swish payment reference') }}: {{ $payment->getPaymentReference() ?: '-' }}<br>
{{ __('Buyer email') }}: {{ $order->getEmail() ?: '-' }}
</p>

<p>
{{ __('Please review the order in the admin panel and either complete it manually or refund the payment to the buyer.') }}
</p>

{{ __('Best regards') }},<br>
{{ config('app.name') }}
</x-mail::message>
