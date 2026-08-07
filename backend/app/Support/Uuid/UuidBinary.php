<?php

namespace App\Support\Uuid;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Converts between standard UUID strings and the ordered BINARY(16)
 * representation produced by MySQL's UUID_TO_BIN(uuid, 1) / BIN_TO_UUID(bin, 1).
 *
 * The swap-flag=1 form reorders the time-low and time-high UUID groups so
 * that the most rapidly changing bits sort first, which keeps BINARY(16)
 * primary key indexes sequential. Every BLUE table using a binary(16) id
 * relies on this exact byte order, so application code must match it.
 */
final class UuidBinary
{
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function toBinary(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Invalid UUID string: {$uuid}");
        }

        $timeLow = substr($hex, 0, 8);
        $timeMid = substr($hex, 8, 4);
        $timeHiAndVersion = substr($hex, 12, 4);
        $clockSeqAndNode = substr($hex, 16, 16);

        return hex2bin($timeHiAndVersion.$timeMid.$timeLow.$clockSeqAndNode);
    }

    public static function toString(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('Invalid UUID binary payload: expected 16 bytes.');
        }

        $hex = bin2hex($binary);

        $timeHiAndVersion = substr($hex, 0, 4);
        $timeMid = substr($hex, 4, 4);
        $timeLow = substr($hex, 8, 8);
        $clockSeqAndNode = substr($hex, 16, 16);

        return sprintf(
            '%s-%s-%s-%s-%s',
            $timeLow,
            $timeMid,
            $timeHiAndVersion,
            substr($clockSeqAndNode, 0, 4),
            substr($clockSeqAndNode, 4, 12)
        );
    }
}
