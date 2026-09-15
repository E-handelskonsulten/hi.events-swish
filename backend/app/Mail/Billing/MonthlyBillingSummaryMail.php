<?php

declare(strict_types=1);

namespace HiEvents\Mail\Billing;

use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Billing\DTO\MonthlyBillingSummaryDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/billing/monthly-summary.blade.php
 */
class MonthlyBillingSummaryMail extends BaseMail
{
    public function __construct(
        public readonly MonthlyBillingSummaryDTO $summary,
        private readonly string $csv,
    ) {
        parent::__construct();

        $this->locale('se');
    }

    public function envelope(): Envelope
    {
        $subject = __('Billing basis :month', ['month' => $this->summary->monthLabel()], 'se');

        if ($this->summary->isEmpty()) {
            $subject .= ' – '.__('nothing to invoice', [], 'se');
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.billing.monthly-summary',
            with: ['summary' => $this->summary],
        );
    }

    public function attachments(): array
    {
        if ($this->summary->isEmpty()) {
            return [];
        }

        $csv = $this->csv;

        return [
            Attachment::fromData(static fn () => $csv, 'faktureringsunderlag-'.$this->summary->periodStart->format('Y-m').'.csv')
                ->withMime('text/csv'),
        ];
    }
}
