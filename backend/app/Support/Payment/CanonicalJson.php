<?php

namespace App\Support\Payment;

use InvalidArgumentException;

/**
 * The one canonicalization implementation used both to persist
 * payment_attempts.checkout_snapshot and to compute
 * payment_attempts.checkout_snapshot_hash - the exact same canonical
 * string is always both stored and hashed, never two different
 * representations (see docs/api-contracts/payments-v1.md "Canonical JSON /
 * hashing").
 *
 * Associative (string-keyed) arrays have their keys sorted recursively so
 * key order can never affect the output. Sequential (list) arrays keep
 * their original order, since order is semantic there (e.g. cart items,
 * pricing adjustments). Floats are rejected outright - every monetary
 * value in this codebase is already a decimal string (see
 * PricingResultAggregator), and floats are the one PHP scalar whose
 * json_encode output is not guaranteed byte-identical across platforms,
 * which would silently break hash determinism.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        $encoded = json_encode(
            self::normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $encoded;
    }

    /**
     * Raw 32-byte SHA-256 digest, matching the BINARY(32)
     * checkout_snapshot_hash / idempotency_key column shape.
     */
    public static function sha256(string $canonicalJson): string
    {
        return hash('sha256', $canonicalJson, binary: true);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('CanonicalJson cannot encode floats - use a decimal string for monetary/precise values.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalize(...), $value);
    }
}
