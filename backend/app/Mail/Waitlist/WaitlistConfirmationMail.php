<?php

namespace HiEvents\Mail\Waitlist;

use Carbon\Carbon;
use HiEvents\Helper\LocaleHelper;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Mail\Concerns\SendsAsOrganizer;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistConfirmationMail extends BaseMail
{
    use SendsAsOrganizer;

    public function __construct(
        private readonly WaitlistEntryDomainObject $entry,
        private readonly EventDomainObject $event,
        private readonly ?ProductDomainObject $product,
        private readonly ?ProductPriceDomainObject $productPrice,
        private readonly OrganizerDomainObject $organizer,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly ?EventOccurrenceDomainObject $occurrence = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerFrom(),
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: __("You're on the waitlist!"),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.waitlist.confirmation',
            with: [
                'entry' => $this->entry,
                'event' => $this->event,
                'productName' => $this->buildProductName(),
                'occurrenceDateFormatted' => $this->formatOccurrenceDate(),
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                'eventUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::EVENT_HOMEPAGE),
                    $this->event->getId(),
                    $this->event->getSlug(),
                ),
            ]
        );
    }

    private function formatOccurrenceDate(): ?string
    {
        if ($this->occurrence === null) {
            return null;
        }

        return Carbon::parse($this->occurrence->getStartDate(), 'UTC')
            ->setTimezone($this->event->getTimezone())
            ->locale(LocaleHelper::toCarbonLocale())
            ->isoFormat(LocaleHelper::pattern('dayAndTime'));
    }

    private function buildProductName(): ?string
    {
        if (! $this->product) {
            return null;
        }

        $name = $this->product->getTitle();

        if ($this->productPrice?->getLabel()) {
            $name .= ' - '.$this->productPrice->getLabel();
        }

        return $name;
    }
}
