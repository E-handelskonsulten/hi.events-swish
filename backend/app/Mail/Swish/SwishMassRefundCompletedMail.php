<?php

declare(strict_types=1);

namespace HiEvents\Mail\Swish;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * @uses /backend/resources/views/emails/swish/mass-refund-completed.blade.php
 */
class SwishMassRefundCompletedMail extends BaseMail
{
    public function __construct(
        private readonly SwishMassRefundRunDomainObject $run,
        private readonly EventDomainObject $event,
        private readonly Collection $failedItems,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Mass refund completed for :eventTitle', [
                'eventTitle' => $this->event->getTitle(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.swish.mass-refund-completed',
            with: [
                'run' => $this->run,
                'event' => $this->event,
                'failedItems' => $this->failedItems,
                'manualOrders' => $this->run->getSummary()['manual_orders'] ?? [],
            ]
        );
    }
}
