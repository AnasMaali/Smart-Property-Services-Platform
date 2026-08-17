<?php

namespace App\Support\Contract;

/**
 * Backend-only generator for service_contracts.contract_number - opaque,
 * ASCII, unique, 6-40 chars (matching chk_service_contracts_number),
 * mirroring App\Support\Booking\BookingNumberGenerator's "never accepted
 * from Flutter, never derived from client input" contract. A short "CTR-"
 * prefix plus 10 base32-ish uppercase hex characters keeps it readable
 * enough for customer support conversations while remaining astronomically
 * unlikely to collide - the contract-creation Action still treats the
 * column's UNIQUE constraint as the authoritative backstop, not this
 * randomness alone.
 */
final class ContractNumberGenerator
{
    public static function generate(): string
    {
        return 'CTR-'.strtoupper(bin2hex(random_bytes(5)));
    }
}
