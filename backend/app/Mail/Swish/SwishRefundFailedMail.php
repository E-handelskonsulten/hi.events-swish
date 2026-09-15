<?php

declare(strict_types=1);

namespace HiEvents\Mail\Swish;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/swish/refund-failed.blade.php
 */
class SwishRefundFailedMail extends BaseMail
{
    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly SwishRefundDomainObject $refund,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Action required: Swish refund failed for order :order', [
                'order' => $this->order->getPublicId(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.swish.refund-failed',
            with: [
                'order' => $this->order,
                'refund' => $this->refund,
            ]
        );
    }
}
