<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }
}