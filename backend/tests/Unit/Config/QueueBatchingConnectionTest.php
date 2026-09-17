<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class QueueBatchingConnectionTest extends TestCase
{
    public function test_job_batches_use_the_default_database_connection(): void
    {
        $this->assertSame(config('database.default'), config('queue.batching.database'));
    }
}
