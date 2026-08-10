<?php

namespace Tests\Unit\Payment;

use App\Support\Payment\CanonicalJson;
use InvalidArgumentException;
use Tests\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_object_keys_are_sorted_regardless_of_input_order(): void
    {
        $a = ['z' => 1, 'a' => 2, 'm' => ['b' => 1, 'a' => 2]];
        $b = ['a' => 2, 'm' => ['a' => 2, 'b' => 1], 'z' => 1];

        $this->assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
    }

    public function test_list_order_is_preserved(): void
    {
        $this->assertSame('[1,2,3]', CanonicalJson::encode([1, 2, 3]));
        $this->assertNotSame(CanonicalJson::encode([1, 2, 3]), CanonicalJson::encode([3, 2, 1]));
    }

    public function test_floats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['amount' => 1.5]);
    }

    public function test_sha256_is_deterministic_and_32_bytes(): void
    {
        $json = CanonicalJson::encode(['a' => 1]);
        $hash = CanonicalJson::sha256($json);

        $this->assertSame(32, strlen($hash));
        $this->assertSame($hash, CanonicalJson::sha256(CanonicalJson::encode(['a' => 1])));
    }

    public function test_decode_reencode_round_trip_is_stable(): void
    {
        // Mirrors what ProcessPaymentWebhookAction relies on: re-decoding a
        // JSON string and re-encoding it canonically must reproduce the
        // exact same bytes, independent of the original key order.
        $original = CanonicalJson::encode(['b' => ['y' => 1, 'x' => 2], 'a' => [1, 2, 3]]);
        $reencoded = CanonicalJson::encode(json_decode($original, true));

        $this->assertSame($original, $reencoded);
    }
}
