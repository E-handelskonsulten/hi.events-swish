<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Jobs\Sms\SendOrderSmsJob;
use HiEvents\Services\Domain\Sms\OrderSmsService;
use Illuminate\Contracts\Queue\Job;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SendOrderSmsJobTest extends TestCase
{
    public function test_a_failed_attempt_is_released_with_backoff_instead_of_throwing(): void
    {
        $queueJob = $this->queueJob(attempts: 1);
        $queueJob->shouldReceive('release')->once()->with(30);
        $queueJob->shouldNotReceive('fail');

        $job = new SendOrderSmsJob(5, SmsMessageType::TICKET);
        $job->setJob($queueJob);

        $job->handle($this->failingService(), new NullLogger);
    }

    public function test_the_last_attempt_marks_the_job_failed_without_throwing(): void
    {
        $queueJob = $this->queueJob(attempts: 3);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('fail')->once()->with(Mockery::type(SmsDeliveryException::class));

        $job = new SendOrderSmsJob(5, SmsMessageType::TICKET);
        $job->setJob($queueJob);

        $job->handle($this->failingService(), new NullLogger);
    }

    public function test_a_successful_send_is_neither_released_nor_failed(): void
    {
        $queueJob = $this->queueJob(attempts: 1);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');

        $service = Mockery::mock(OrderSmsService::class);
        $service->shouldReceive('send')->once()->with(5, SmsMessageType::REFUND_NOTICE);

        $job = new SendOrderSmsJob(5, SmsMessageType::REFUND_NOTICE);
        $job->setJob($queueJob);

        $job->handle($service, new NullLogger);
    }

    private function queueJob(int $attempts): MockInterface&Job
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('isReleased', 'isDeleted', 'isDeletedOrReleased', 'hasFailed')->andReturn(false);

        return $queueJob;
    }

    private function failingService(): OrderSmsService
    {
        $service = Mockery::mock(OrderSmsService::class);
        $service->shouldReceive('send')->once()->andThrow(new SmsDeliveryException('46elks rejected the SMS (HTTP 403): Invalid from'));

        return $service;
    }
}
