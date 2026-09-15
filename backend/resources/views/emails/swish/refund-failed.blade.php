@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\SwishRefundDomainObject $refund */ @endphp

@php /** @see \HiEvents\Mail\Swish\SwishRefundFailedMail */ @endphp

<x-mail::message>
{{ __('Hello') }},

<p>
{{ __('The Swish refund of :amount for order :order failed.', [
    'amount' => \HiEvents\Helper\Currency::format($refund->getAmount(), $refund->getCurrency()),
    'order' => $order->getPublicId(),
]) }}
</p>

<p>
{{ __('Error code') }}: <strong>{{ $refund->getErrorCode() ?: '-' }}</strong><br>
{{ __('Error message') }}: {{ $refund->getErrorMessage() ?: '-' }}<br>
{{ __('Instruction reference') }}: {{ $refund->getInstructionUuid() }}<br>
{{ __('Buyer email') }}: {{ $order->getEmail() ?: '-' }}
</p>

<p>
{{ __('The order is marked as refund failed. Retry the refund from the order page in the admin panel or refund the buyer manually.') }}
</p>

{{ __('Best regards') }},<br>
{{ config('app.name') }}
</x-mail::message>
