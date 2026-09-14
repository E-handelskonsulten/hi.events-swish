<?php

declare(strict_types=1);

namespace HiEvents\Mail\Swish;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/swish/payment-needs-review.blade.php
 */
class SwishPaymentNeedsReviewMail extends BaseMail
{
    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly EventDomainObject $event,
        private readonly SwishPaymentDomainObject $payment,
        private readonly string $reason,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Action required: Swish payment received for order :order', [
                'order' => $this->order->getPublicId(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.swish.payment-needs-review',
            with: [
                'event' => $this->event,
                'order' => $this->order,
                'payment' => $this->payment,
                'reason' => $this->reason,
            ]
        );
    }
}
