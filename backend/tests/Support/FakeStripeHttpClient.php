<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * A Stripe SDK-compatible transport test double, installed via
 * \Stripe\ApiRequestor::setHttpClient() so StripePaymentGateway tests never
 * make a real HTTP call - stripe-php's own HttpClient\ClientInterface is
 * the one boundary the real SDK already lets a caller swap out, so this
 * exercises every layer of StripePaymentGateway (request building, response
 * parsing, exception construction from an HTTP error body) except the
 * actual socket.
 *
 * Every captured request is recorded in $requests so a test can assert on
 * the exact payload StripePaymentGateway sent (amount, currency, metadata,
 * idempotency key header, ...), independent of asserting the response it
 * got back.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{status: int, body: array<string, mixed>}|array{throw: Throwable}> */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function queueResponse(int $status, array $body): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
    }

    public function queueException(Throwable $exception): void
    {
        $this->queue[] = ['throw' => $exception];
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @param  'v1'|'v2'  $apiMode
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        if ($this->queue === []) {
            throw new RuntimeException("FakeStripeHttpClient: no queued response for {$method} {$absUrl}.");
        }

        $next = array_shift($this->queue);

        if (isset($next['throw'])) {
            throw $next['throw'];
        }

        return [json_encode($next['body'], JSON_THROW_ON_ERROR), $next['status'], []];
    }
}
