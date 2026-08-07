<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard: the test suite is only ever allowed to run against
     * blue_test_db. This prevents an accidental `php artisan test` run
     * (wrong .env, missing phpunit.xml override, etc.) from writing to
     * blue_db.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $activeDatabase = DB::connection()->getDatabaseName();

        if ($activeDatabase !== 'blue_test_db') {
            throw new RuntimeException(
                "Refusing to run tests against database [{$activeDatabase}]. ".
                "Tests must run against 'blue_test_db' only (see phpunit.xml DB_DATABASE)."
            );
        }
    }
}
