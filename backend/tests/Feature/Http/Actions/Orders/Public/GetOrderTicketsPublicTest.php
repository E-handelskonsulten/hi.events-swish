<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\Orders\Public;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\Domain\Payment\Swish\SwishFeatureTestCase;

class GetOrderTicketsPublicTest extends SwishFeatureTestCase
{
    public function test_a_completed_order_returns_every_ticket_without_a_session(): void
    {
        $orderId = $this->createPaidSwishOrder();
        $this->addAttendees($orderId, ['Erik', 'Maja']);
        $shortId = $this->orderRow($orderId)->short_id;

        $this->flushSession();
        $response = $this->getJson("/public/orders/{$shortId}/tickets");

        $response->assertOk();
        $this->assertSame($shortId, $response->json('data.short_id'));
        $this->assertSame($this->eventId, $response->json('data.event_id'));
        $this->assertTrue($response->json('data.is_valid'));
        $this->assertFalse($response->json('data.is_fully_refunded'));
        $this->assertSame(['Test', 'Erik', 'Maja'], $response->json('data.attendees.*.first_name'));
        $this->assertSame(['ACTIVE', 'ACTIVE', 'ACTIVE'], $response->json('data.attendees.*.status'));
        $this->assertCount(3, array_unique($response->json('data.attendees.*.public_id')));
        $this->assertSame('Entry ticket', $response->json('data.attendees.0.product.title'));
        $this->assertArrayNotHasKey('email', $response->json('data'));
    }

    public function test_a_wrong_or_guessed_token_is_not_found(): void
    {
        $orderId = $this->createPaidSwishOrder();
        $shortId = $this->orderRow($orderId)->short_id;

        $this->getJson('/public/orders/'.substr($shortId, 0, -1).'x/tickets')->assertNotFound();
        $this->getJson("/public/orders/{$orderId}/tickets")->assertNotFound();
        $this->getJson('/public/orders/'.$this->orderRow($orderId)->public_id.'/tickets')->assertNotFound();
    }

    public function test_a_reserved_order_is_not_exposed(): void
    {
        $orderId = $this->createReservedOrder();

        $this->getJson("/public/orders/{$this->orderRow($orderId)->short_id}/tickets")->assertNotFound();
    }

    public function test_a_refunded_order_marks_every_ticket_invalid(): void
    {
        $orderId = $this->createPaidSwishOrder();
        $this->addAttendees($orderId, ['Erik']);
        DB::table('orders')->where('id', $orderId)->update([
            'total_refunded' => self::ORDER_TOTAL,
            'refund_status' => OrderRefundStatus::REFUNDED->name,
        ]);

        $response = $this->getJson("/public/orders/{$this->orderRow($orderId)->short_id}/tickets");

        $response->assertOk();
        $this->assertFalse($response->json('data.is_valid'));
        $this->assertTrue($response->json('data.is_fully_refunded'));
        $this->assertCount(2, $response->json('data.attendees'));
    }

    public function test_a_cancelled_attendee_is_listed_with_its_status(): void
    {
        $orderId = $this->createPaidSwishOrder();
        [$cancelledId] = $this->addAttendees($orderId, ['Erik']);
        DB::table('attendees')->where('id', $cancelledId)->update(['status' => AttendeeStatus::CANCELLED->name]);

        $response = $this->getJson("/public/orders/{$this->orderRow($orderId)->short_id}/tickets");

        $this->assertTrue($response->json('data.is_valid'));
        $this->assertSame(['ACTIVE', 'CANCELLED'], $response->json('data.attendees.*.status'));
    }

    public function test_every_code_shown_on_the_page_can_be_checked_in_at_the_door(): void
    {
        $orderId = $this->createPaidSwishOrder();
        $this->addAttendees($orderId, ['Erik', 'Maja']);
        $publicIds = $this->getJson("/public/orders/{$this->orderRow($orderId)->short_id}/tickets")->json('data.attendees.*.public_id');

        $list = $this->postJson("/events/{$this->eventId}/check-in-lists", [
            'name' => 'Door',
            'product_ids' => [$this->productId],
        ], $this->authHeaders())->assertOk()->json('data');

        foreach ($publicIds as $publicId) {
            $this->postJson("/public/check-in-lists/{$list['short_id']}/check-ins", [
                'attendees' => [['public_id' => $publicId, 'action' => 'check-in']],
            ])->assertOk();
        }

        $this->assertSame(3, DB::table('attendee_check_ins')->where('check_in_list_id', $list['id'])->whereNull('deleted_at')->count());
    }

    /**
     * @return int[]
     */
    private function addAttendees(int $orderId, array $firstNames): array
    {
        $ids = [];

        foreach ($firstNames as $firstName) {
            $suffix = uniqid();
            $ids[] = DB::table('attendees')->insertGetId([
                'short_id' => 'a_'.$suffix,
                'public_id' => 'A-'.strtoupper($suffix),
                'email' => 'buyer@swish.test',
                'first_name' => $firstName,
                'last_name' => 'Buyer',
                'order_id' => $orderId,
                'product_id' => $this->productId,
                'product_price_id' => $this->productPriceId,
                'event_id' => $this->eventId,
                'status' => AttendeeStatus::ACTIVE->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('attendees')->where('order_id', $orderId)->update(['status' => AttendeeStatus::ACTIVE->name]);
        DB::table('orders')->where('id', $orderId)->update([
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
        ]);

        return $ids;
    }
}
