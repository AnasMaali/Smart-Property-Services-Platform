<?php

namespace Tests\Unit\Support;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UuidBinaryTest extends TestCase
{
    public function test_to_binary_matches_mysql_uuid_to_bin_with_swap_flag(): void
    {
        $uuid = UuidBinary::generate();

        $phpBinary = UuidBinary::toBinary($uuid);

        $mysqlBinary = DB::selectOne('SELECT UUID_TO_BIN(?, 1) AS bin', [$uuid])->bin;

        $this->assertSame($mysqlBinary, $phpBinary);
    }

    public function test_to_string_matches_mysql_bin_to_uuid_with_swap_flag(): void
    {
        $uuid = UuidBinary::generate();
        $binary = UuidBinary::toBinary($uuid);

        $mysqlUuid = DB::selectOne('SELECT BIN_TO_UUID(?, 1) AS uuid', [$binary])->uuid;

        $this->assertSame($mysqlUuid, UuidBinary::toString($binary));
        $this->assertSame(strtolower($uuid), $mysqlUuid);
    }

    public function test_round_trip_through_mysql_preserves_the_uuid(): void
    {
        $uuid = UuidBinary::generate();

        $roundTripped = DB::selectOne(
            'SELECT BIN_TO_UUID(UUID_TO_BIN(?, 1), 1) AS uuid',
            [$uuid]
        )->uuid;

        $this->assertSame(strtolower($uuid), $roundTripped);
        $this->assertSame($uuid, UuidBinary::toString(UuidBinary::toBinary($uuid)));
    }

    public function test_round_trip_through_php_only_preserves_the_uuid(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $uuid = UuidBinary::generate();

            $this->assertSame($uuid, UuidBinary::toString(UuidBinary::toBinary($uuid)));
        }
    }
}
