<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\OrderAuditAction;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Enums\SwishMassRefundManualReason;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundItemStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Jobs\Order\Swish\ProcessActiveSwishMassRefundRunsJob;
use HiEvents\Jobs\Order\Swish\ProcessSwishMassRefundRunJob;
use HiEvents\Jobs\Order\Swish\ResumeStalledSwishMassRefundRunsJob;
use HiEvents\Mail\Swish\SwishMassRefundCompletedMail;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundProcessor;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Psr\Log\LoggerInterface;

class SwishMassRefundTest extends SwishFeatureTestCase
{
    private const EVENT_TITLE = 'Swish Test Event';

    public function test_preview_summarises_refundable_orders_and_lists_manual_handling(): void
    {
        $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $this->createPaidSwishOrder(paymentReference: 'REF-B', total: 100);
        $partiallyRefunded = $this->createPaidSwishOrder(paymentReference: 'REF-C', total: 60);
        DB::table('orders')->where('id', $partiallyRefunded)->update([
            'total_refunded' => 20,
            'refund_status' => OrderRefundStatus::PARTIALLY_REFUNDED->name,
        ]);

        $old = $this->createPaidSwishOrder(paymentReference: 'REF-OLD', datePaid: now()->subMonths(13)->toDateTimeString());
        $failed = $this->createPaidSwishOrder(paymentReference: 'REF-FAILED');
        DB::table('orders')->where('id', $failed)->update(['refund_status' => OrderRefundStatus::REFUND_FAILED->name]);
        $pending = $this->createPaidSwishOrder(paymentReference: 'REF-PENDING');
        DB::table('orders')->where('id', $pending)->update(['refund_status' => OrderRefundStatus::REFUND_PENDING->name]);

        $alreadyRefunded = $this->createPaidSwishOrder(paymentReference: 'REF-DONE');
        DB::table('orders')->where('id', $alreadyRefunded)->update([
            'total_refunded' => 25,
            'refund_status' => OrderRefundStatus::REFUNDED->name,
        ]);
        $this->createReservedOrder();

        $response = $this->getJson("/events/{$this->eventId}/swish-mass-refunds/preview", $this->authHeaders());

        $response->assertOk();
        $this->assertSame(self::EVENT_TITLE, $response->json('data.event_title'));
        $this->assertSame(3, $response->json('data.refundable_count'));
        $this->assertEqualsWithDelta(165.0, $response->json('data.total_amount'), 0.001);
        $this->assertSame(3, $response->json('data.manual_count'));
        $this->assertEqualsWithDelta(75.0, $response->json('data.manual_amount'), 0.001);
        $this->assertNull($response->json('data.active_run_id'));

        $ticketTypes = $response->json('data.ticket_types');
        $this->assertCount(1, $ticketTypes);
        $this->assertSame('Entry ticket', $ticketTypes[0]['name']);
        $this->assertSame(3, $ticketTypes[0]['quantity']);
        $this->assertSame(3, $ticketTypes[0]['order_count']);

        $reasons = collect($response->json('data.manual_orders'))->pluck('reason', 'order_id');
        $this->assertSame(SwishMassRefundManualReason::PAYMENT_OLDER_THAN_12_MONTHS->value, $reasons[$old]);
        $this->assertSame(SwishMassRefundManualReason::PREVIOUS_REFUND_FAILED->value, $reasons[$failed]);
        $this->assertSame(SwishMassRefundManualReason::REFUND_PENDING->value, $reasons[$pending]);
        $this->assertNotNull(collect($response->json('data.manual_orders'))->firstWhere('order_id', $old)['reason_label']);
    }

    public function test_start_requires_the_exact_event_title(): void
    {
        Queue::fake();
        $this->createPaidSwishOrder();

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => 'swish test event',
            'notify_buyers' => false,
            'cancel_orders' => false,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation']);

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => '',
            'notify_buyers' => false,
            'cancel_orders' => false,
        ], $this->authHeaders())->assertStatus(422);

        $this->assertSame(0, DB::table('swish_mass_refund_runs')->count());
        Queue::assertNothingPushed();
    }

    public function test_start_creates_a_run_with_one_item_per_refundable_order_and_queues_processing(): void
    {
        Queue::fake();
        $orderA = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $orderB = $this->createPaidSwishOrder(paymentReference: 'REF-B', total: 40);
        $this->createPaidSwishOrder(paymentReference: 'REF-OLD', datePaid: now()->subMonths(14)->toDateTimeString());

        $response = $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => self::EVENT_TITLE,
            'notify_buyers' => true,
            'cancel_orders' => true,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $runId = $response->json('data.id');
        $this->assertSame(SwishMassRefundRunStatus::PENDING->value, $response->json('data.status'));
        $this->assertSame(2, $response->json('data.total_orders'));
        $this->assertEqualsWithDelta(65.0, $response->json('data.total_amount'), 0.001);
        $this->assertSame(1, $response->json('data.manual_count'));
        $this->assertSame($this->user->id, $response->json('data.initiated_by_user_id'));
        $this->assertNotEmpty($response->json('data.initiated_by_name'));
        $this->assertCount(1, $response->json('data.summary.manual_orders'));

        $items = DB::table('swish_mass_refund_items')->where('run_id', $runId)->orderBy('order_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame([$orderA, $orderB], $items->pluck('order_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([SwishMassRefundItemStatus::PENDING->value], $items->pluck('status')->unique()->all());

        Queue::assertPushed(ProcessSwishMassRefundRunJob::class, fn (ProcessSwishMassRefundRunJob $job) => $job->runId === $runId);

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => self::EVENT_TITLE,
            'notify_buyers' => true,
            'cancel_orders' => true,
        ], $this->authHeaders())->assertStatus(409);
    }

    public function test_start_is_rejected_when_nothing_can_be_refunded(): void
    {
        Queue::fake();
        $order = $this->createPaidSwishOrder();
        DB::table('orders')->where('id', $order)->update(['total_refunded' => 25, 'refund_status' => OrderRefundStatus::REFUNDED->name]);

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => self::EVENT_TITLE,
            'notify_buyers' => false,
            'cancel_orders' => false,
        ], $this->authHeaders())->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_organizer_role_cannot_use_mass_refunds(): void
    {
        $organizer = User::factory()->create();
        $organizer->accounts()->attach($this->accountId, [
            'role' => Role::ORGANIZER->name,
            'status' => UserStatus::ACTIVE->name,
            'is_account_owner' => false,
        ]);
        $token = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($organizer);
        $this->app['auth']->forgetGuards();

        $this->getJson("/events/{$this->eventId}/swish-mass-refunds/preview", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    public function test_processing_continues_after_an_individual_swish_failure_and_throttles_batches(): void
    {
        Queue::fake();
        $this->setBatchSize(2);

        $orderA = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $orderB = $this->createPaidSwishOrder(paymentReference: 'REF-B');
        $orderC = $this->createPaidSwishOrder(paymentReference: 'REF-C');
        $runId = $this->startRun(cancelOrders: true);

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/A']);
        $this->queueSwishResponse(422, ['Content-Type' => 'application/json'], json_encode([
            ['errorCode' => 'RF07', 'errorMessage' => 'Transaction declined'],
        ], JSON_THROW_ON_ERROR));

        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $orderA, SwishMassRefundItemStatus::REQUESTED);
        $this->assertItemStatus($runId, $orderB, SwishMassRefundItemStatus::FAILED);
        $this->assertItemStatus($runId, $orderC, SwishMassRefundItemStatus::PENDING);
        $this->assertSame('RF07', DB::table('swish_mass_refund_items')->where('run_id', $runId)->where('order_id', $orderB)->value('error_code'));

        $this->assertSame(OrderStatus::CANCELLED->name, $this->orderRow($orderA)->status);
        $this->assertSame(OrderRefundStatus::REFUND_PENDING->name, $this->orderRow($orderA)->refund_status);
        $this->assertSame(OrderStatus::CANCELLED->name, $this->orderRow($orderB)->status);
        $this->assertNull($this->orderRow($orderB)->refund_status);

        $run = DB::table('swish_mass_refund_runs')->where('id', $runId)->first();
        $this->assertSame(SwishMassRefundRunStatus::RUNNING->value, $run->status);
        $this->assertSame(1, (int) $run->pending_count);
        $this->assertSame(1, (int) $run->requested_count);
        $this->assertSame(1, (int) $run->failed_count);
        $this->assertNotNull($run->started_at);

        Queue::assertPushedTimes(ProcessSwishMassRefundRunJob::class, 1);

        $this->assertSame(1, DB::table('order_audit_logs')->where('order_id', $orderA)->where('action', OrderAuditAction::MASS_REFUND_REQUESTED->value)->count());
        $this->assertSame(1, DB::table('order_audit_logs')->where('order_id', $orderB)->where('action', OrderAuditAction::MASS_REFUND_FAILED->value)->count());
    }

    public function test_run_completes_once_every_refund_has_settled_and_emails_the_organizer(): void
    {
        Queue::fake();
        Mail::fake();

        $orderA = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $orderB = $this->createPaidSwishOrder(paymentReference: 'REF-B');
        $runId = $this->startRun();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/A']);
        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/B']);
        $this->processor()->processBatch($runId);

        $this->assertSame(SwishMassRefundRunStatus::RUNNING->value, DB::table('swish_mass_refund_runs')->where('id', $runId)->value('status'));
        Mail::assertNothingQueued();

        DB::table('swish_refunds')->where('order_id', $orderA)->update(['status' => SwishRefundStatus::PAID->value]);
        DB::table('swish_refunds')->where('order_id', $orderB)->update([
            'status' => SwishRefundStatus::ERROR->value,
            'error_code' => 'RF08',
            'error_message' => 'Refund could not be performed',
        ]);

        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $orderA, SwishMassRefundItemStatus::SUCCEEDED);
        $this->assertItemStatus($runId, $orderB, SwishMassRefundItemStatus::FAILED);

        $run = DB::table('swish_mass_refund_runs')->where('id', $runId)->first();
        $this->assertSame(SwishMassRefundRunStatus::COMPLETED->value, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, (int) $run->succeeded_count);
        $this->assertSame(1, (int) $run->failed_count);
        $this->assertEqualsWithDelta(25.0, (float) $run->succeeded_amount, 0.001);

        Mail::assertQueued(SwishMassRefundCompletedMail::class);
        Queue::assertPushedTimes(ProcessSwishMassRefundRunJob::class, 1);

        $response = $this->getJson("/events/{$this->eventId}/swish-mass-refunds/{$runId}", $this->authHeaders());
        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame('RF08', collect($response->json('data.items'))->firstWhere('order_id', $orderB)['error_code']);
    }

    public function test_re_running_skips_orders_refunded_in_the_meantime(): void
    {
        Queue::fake();
        Mail::fake();

        $orderA = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $orderB = $this->createPaidSwishOrder(paymentReference: 'REF-B');
        $runId = $this->startRun();

        DB::table('orders')->where('id', $orderA)->update(['total_refunded' => 25, 'refund_status' => OrderRefundStatus::REFUNDED->name]);

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/B']);
        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $orderA, SwishMassRefundItemStatus::SKIPPED);
        $this->assertItemStatus($runId, $orderB, SwishMassRefundItemStatus::REQUESTED);
        $this->assertSame(1, DB::table('swish_refunds')->count());
        $this->assertSame(1, DB::table('order_audit_logs')->where('order_id', $orderA)->where('action', OrderAuditAction::MASS_REFUND_SKIPPED->value)->count());

        DB::table('swish_refunds')->where('order_id', $orderB)->update(['status' => SwishRefundStatus::PAID->value]);
        DB::table('orders')->where('id', $orderB)->update(['total_refunded' => 25, 'refund_status' => OrderRefundStatus::REFUNDED->name]);
        $this->processor()->processBatch($runId);

        $this->assertSame(SwishMassRefundRunStatus::COMPLETED->value, DB::table('swish_mass_refund_runs')->where('id', $runId)->value('status'));

        $preview = $this->getJson("/events/{$this->eventId}/swish-mass-refunds/preview", $this->authHeaders());
        $this->assertSame(0, $preview->json('data.refundable_count'));

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => self::EVENT_TITLE,
            'notify_buyers' => false,
            'cancel_orders' => false,
        ], $this->authHeaders())->assertStatus(409);
    }

    public function test_an_in_flight_refund_created_before_a_crash_is_adopted_instead_of_duplicated(): void
    {
        Queue::fake();

        $order = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $runId = $this->startRun();

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/A']);
        $this->postJson("/events/{$this->eventId}/orders/{$order}/refund", [
            'amount' => 25,
            'notify_buyer' => false,
            'cancel_order' => false,
        ], $this->authHeaders())->assertOk();

        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $order, SwishMassRefundItemStatus::REQUESTED);
        $this->assertSame(1, DB::table('swish_refunds')->where('order_id', $order)->count());
        $this->assertSame(
            (int) DB::table('swish_refunds')->where('order_id', $order)->value('id'),
            (int) DB::table('swish_mass_refund_items')->where('run_id', $runId)->value('swish_refund_id'),
        );
    }

    public function test_a_stalled_run_is_resumed_by_the_monitor_job(): void
    {
        Queue::fake();

        $order = $this->createPaidSwishOrder();
        $runId = $this->startRun();
        Queue::assertPushedTimes(ProcessSwishMassRefundRunJob::class, 1);

        DB::table('swish_mass_refund_items')->where('run_id', $runId)->update([
            'status' => SwishMassRefundItemStatus::PROCESSING->value,
            'claimed_at' => now()->subMinutes(10),
        ]);
        DB::table('swish_mass_refund_runs')->where('id', $runId)->update([
            'status' => SwishMassRefundRunStatus::RUNNING->value,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $freshRunId = $this->startFreshRunForResumeTest();

        $this->app->make(ResumeStalledSwishMassRefundRunsJob::class)->handle(
            $this->app->make(SwishMassRefundRunsRepositoryInterface::class),
            $this->processor(),
            $this->app->make(Repository::class),
            $this->app->make(LoggerInterface::class),
        );

        $this->assertItemStatus($runId, $order, SwishMassRefundItemStatus::PENDING);
        $this->assertSame(1, DB::table('swish_mass_refund_runs')->where('id', $freshRunId)->where('last_activity_at', '>', now()->subMinute())->count());
        $this->assertNotNull(DB::table('swish_mass_refund_runs')->where('id', $runId)->value('last_activity_at'));

        $this->app->make(ProcessActiveSwishMassRefundRunsJob::class)->handle($this->app->make(SwishMassRefundRunsRepositoryInterface::class));

        $pushedRunIds = collect(Queue::pushed(ProcessSwishMassRefundRunJob::class))->map(fn ($job) => $job->runId);
        $this->assertSame(2, $pushedRunIds->filter(fn ($id) => $id === $runId)->count());
        $this->assertSame(1, $pushedRunIds->filter(fn ($id) => $id === $freshRunId)->count());
    }

    public function test_the_scheduler_tick_only_processes_active_runs(): void
    {
        Queue::fake();

        $this->createPaidSwishOrder();
        $runId = $this->startRun();
        DB::table('swish_mass_refund_runs')->where('id', $runId)->update(['status' => SwishMassRefundRunStatus::COMPLETED->value]);

        $this->app->make(ProcessActiveSwishMassRefundRunsJob::class)->handle($this->app->make(SwishMassRefundRunsRepositoryInterface::class));

        Queue::assertPushedTimes(ProcessSwishMassRefundRunJob::class, 1);
    }

    public function test_failed_items_can_be_retried_after_completion(): void
    {
        Queue::fake();
        Mail::fake();

        $order = $this->createPaidSwishOrder(paymentReference: 'REF-A');
        $runId = $this->startRun();

        $this->postJson("/events/{$this->eventId}/swish-mass-refunds/{$runId}/retry", [], $this->authHeaders())->assertStatus(409);

        $this->queueSwishResponse(422, ['Content-Type' => 'application/json'], json_encode([
            ['errorCode' => 'RF07', 'errorMessage' => 'Transaction declined'],
        ], JSON_THROW_ON_ERROR));
        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $order, SwishMassRefundItemStatus::FAILED);
        $this->assertSame(SwishMassRefundRunStatus::COMPLETED->value, DB::table('swish_mass_refund_runs')->where('id', $runId)->value('status'));

        $response = $this->postJson("/events/{$this->eventId}/swish-mass-refunds/{$runId}/retry", [], $this->authHeaders());

        $response->assertOk();
        $this->assertSame(SwishMassRefundRunStatus::RUNNING->value, $response->json('data.status'));
        $this->assertSame(1, $response->json('data.pending_count'));
        $this->assertSame(0, $response->json('data.failed_count'));
        $this->assertItemStatus($runId, $order, SwishMassRefundItemStatus::PENDING);
        Queue::assertPushedTimes(ProcessSwishMassRefundRunJob::class, 2);

        $this->queueSwishResponse(201, ['Location' => 'https://swish.test/swish-cpcapi/api/v1/refunds/A']);
        $this->processor()->processBatch($runId);

        $this->assertItemStatus($runId, $order, SwishMassRefundItemStatus::REQUESTED);
        $this->assertSame(2, (int) DB::table('swish_mass_refund_items')->where('run_id', $runId)->value('attempts'));
    }

    private function startRun(bool $notifyBuyers = false, bool $cancelOrders = false): int
    {
        $response = $this->postJson("/events/{$this->eventId}/swish-mass-refunds", [
            'confirmation' => self::EVENT_TITLE,
            'notify_buyers' => $notifyBuyers,
            'cancel_orders' => $cancelOrders,
        ], $this->authHeaders());

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    private function startFreshRunForResumeTest(): int
    {
        $now = now()->toDateTimeString();
        $eventId = DB::table('events')->insertGetId([
            'title' => 'Other Event',
            'account_id' => $this->accountId,
            'user_id' => $this->user->id,
            'organizer_id' => $this->organizerId,
            'currency' => 'SEK',
            'timezone' => 'Europe/Stockholm',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('swish_mass_refund_runs')->insertGetId([
            'event_id' => $eventId,
            'account_id' => $this->accountId,
            'status' => SwishMassRefundRunStatus::RUNNING->value,
            'currency' => 'SEK',
            'total_orders' => 1,
            'total_amount' => 25,
            'pending_count' => 1,
            'last_activity_at' => now(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function processor(): SwishMassRefundProcessor
    {
        return $this->app->make(SwishMassRefundProcessor::class);
    }

    private function setBatchSize(int $size): void
    {
        $this->app->make(Repository::class)->set('swish.mass_refund.batch_size', $size);
    }

    private function assertItemStatus(int $runId, int $orderId, SwishMassRefundItemStatus $expected): void
    {
        $item = DB::table('swish_mass_refund_items')->where('run_id', $runId)->where('order_id', $orderId)->first();

        $this->assertSame($expected->value, $item->status, "Order $orderId should be {$expected->value}, got {$item->status} ({$item->error_message})");
    }
}
