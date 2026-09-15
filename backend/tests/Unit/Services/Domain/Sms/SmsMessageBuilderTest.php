<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

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

    public function test_swedish_ticket_message_links_to_the_order_page(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::TICKET, $this->order('Lucas', 'se'), $this->event());

        $this->assertSame('Hej Lucas! Din biljett till Lördagsklubben: https://demo.test/checkout/7/O-ABC123/summary', $message);
    }

    public function test_english_refund_notice(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::REFUND_NOTICE, $this->order('Lucas', 'en'), $this->event());

        $this->assertSame('Hi Lucas! Your order for Lördagsklubben has been refunded and the ticket is no longer valid.', $message);
    }

    public function test_greeting_without_a_first_name(): void
    {
        $message = (new SmsMessageBuilder)->build(SmsMessageType::REFUND_NOTICE, $this->order('', 'se'), $this->event());

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
