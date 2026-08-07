<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_blue_api_health_endpoint_is_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'BLUE API is running',
                'database' => 'connected',
            ]);
    }
}
