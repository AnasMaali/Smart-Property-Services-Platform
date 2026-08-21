<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * BLUE V1's customer client (Flutter) is never subject to browser CORS -
 * this only matters for the Admin Web frontend and any other browser-based
 * client. config/cors.php is env-driven (CORS_ALLOWED_ORIGINS); the test
 * environment leaves it unset, so it defaults to "*" - the same behavior
 * this project already had via Laravel's own vendor default before
 * config/cors.php existed, now explicit and configurable.
 */
class CorsTest extends TestCase
{
    public function test_cors_config_defaults_to_wildcard_origin_and_never_supports_credentials(): void
    {
        $this->assertSame(['*'], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertSame(['api/*'], config('cors.paths'));
    }

    // With the default (unconfigured) CORS_ALLOWED_ORIGINS, every origin is
    // allowed and Access-Control-Allow-Origin is the literal wildcard - the
    // moment a production deployment sets CORS_ALLOWED_ORIGINS to a real
    // origin list, this same middleware instead reflects only the
    // matching request Origin (config/cors.php documents this).
    // Access-Control-Allow-Credentials must never be sent, matching
    // supports_credentials => false regardless of allowed_origins.
    public function test_preflight_request_is_allowed_under_the_default_wildcard_config_and_never_sets_credentials(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://admin.example.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/service-categories');

        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    // Authorization must remain usable cross-origin for the Admin Web
    // frontend, and Idempotency-Key for POST /v1/payments.
    public function test_preflight_allows_authorization_and_idempotency_key_headers(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://admin.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Idempotency-Key, Content-Type',
        ])->options('/api/v1/payments');

        $response->assertStatus(204);
        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $allowedHeaders);
        $this->assertStringContainsString('idempotency-key', $allowedHeaders);
    }
}
