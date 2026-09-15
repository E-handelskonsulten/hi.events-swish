<?php

namespace HiEvents\Http\Actions\Health;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Throwable;

class HealthCheckAction extends BaseAction
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly RedisFactory $redis,
    ) {}

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => $this->databaseManager->select('select 1')),
            'redis' => $this->check(fn () => $this->redis->connection()->ping()),
        ];

        $healthy = ! in_array('failed', $checks, true);

        return $this->jsonResponse(
            data: [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
            ],
            statusCode: $healthy ? ResponseCodes::HTTP_OK : ResponseCodes::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }
}
