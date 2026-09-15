<?php

namespace Tests\Feature\Http\Actions\Health;

use Tests\TestCase;

class HealthCheckActionTest extends TestCase
{
    public function test_health_endpoint_reports_database_and_redis(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database', 'ok');
        $response->assertJsonPath('checks.redis', 'ok');
    }

    public function test_health_endpoint_needs_no_authentication(): void
    {
        $this->getJson('/health')->assertOk();
    }
}
