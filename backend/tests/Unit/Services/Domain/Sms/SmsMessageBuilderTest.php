<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Services\Domain\Sms\SmsMessageBuilder;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SmsMessageBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.frontend_url', 'https://demo.test');
    }

    public function test_a_single_ticket_links_to_the_attendee_ticket_page(): void
    {
        $attendee = (new AttendeeDomainObject)->setId(9)->setShortId('a_first');

        $message = (new SmsMessageBuilder)->build(SmsMessageType::TICKET, $this->order('Lucas', 'se'), $this->event(), collect([$attendee]));

        $this->assertSame('Hej Lucas! Din biljett till Lördagsklubben: https://demo.test/product/7/a_first', $message);
    }

    public function test_several_tickets_link_to_the_order_tickets_page(): void
    {
        $attendees = collect([
            (new AttendeeDomainObject)->setId(9)->setShortId('a_first'),
            (new AttendeeDomainObject)->setId(10)->setShortId('a_second'),
        ]);

        $message = (new SmsMessageBuilder)->build(SmsMessageType::TICKET, $this->order('Lucas', 'se'), $this->event(), $attendees);

        $this->assertSame('Hej Lucas! Dina biljetter till Lördagsklubben: https://demo.test/t/O-ABC123', $message);
    }

    public function test_ticket_message_falls_back_to_the_order_tickets_page_without_attendees(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::TICKET, $this->order('Lucas', 'en'), $this->event(), collect());

        $this->assertSame('Hi Lucas! Your ticket for Lördagsklubben: https://demo.test/t/O-ABC123', $message);
    }

    public function test_english_refund_notice(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::REFUND_NOTICE, $this->order('Lucas', 'en'), $this->event(), collect());

        $this->assertSame('Hi Lucas! Your order for Lördagsklubben has been refunded and the ticket is no longer valid.', $message);
    }

    public function test_greeting_without_a_first_name(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::REFUND_NOTICE, $this->order('', 'se'), $this->event(), collect());

        $this->assertStringStartsWith('Hej! Din order för Lördagsklubben', $message);
    }

    private function order(string $firstName, string $locale): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(42)
            ->setShortId('O-ABC123')
            ->setFirstName($firstName)
            ->setLocale($locale);
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)->setId(7)->setTitle('Lördagsklubben');
    }
}
