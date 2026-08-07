<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Provisions blue_test_db directly from the repository's authoritative
 * database/blue_v1_schema.sql and database/blue_v1_seed.sql files.
 *
 * No copy of either file is ever written to disk: the seed file's only
 * required edit (USE blue_db; -> USE blue_test_db;) is applied in memory
 * and streamed straight into the mysql client's STDIN. Credentials are
 * never embedded here; they are resolved at runtime from the active
 * Laravel database connection config (env-driven, same as the app itself).
 */
class ProvisionTestDatabase extends Command
{
    protected $signature = 'blue:provision-test-db';

    protected $description = 'Provision blue_test_db schema and reference data from the authoritative database/*.sql files (refuses to target any other database).';

    public function handle(): int
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");
        $databaseName = $config['database'] ?? null;

        if ($databaseName !== 'blue_test_db') {
            $this->error("Refusing to run: active database is [{$databaseName}], expected [blue_test_db].");
            $this->line('Re-run with: DB_DATABASE=blue_test_db php artisan blue:provision-test-db');

            return self::FAILURE;
        }

        $repositoryRoot = dirname(base_path());
        $schemaPath = $repositoryRoot.'/database/blue_v1_schema.sql';
        $seedPath = $repositoryRoot.'/database/blue_v1_seed.sql';

        if (! is_file($schemaPath) || ! is_file($seedPath)) {
            $this->error('Authoritative files not found under /database (blue_v1_schema.sql / blue_v1_seed.sql).');

            return self::FAILURE;
        }

        try {
            $this->info('Applying blue_v1_schema.sql to blue_test_db...');
            $this->streamIntoMysql($config, file_get_contents($schemaPath));

            $this->info('Applying blue_v1_seed.sql (reference data only) to blue_test_db...');
            $seedSql = preg_replace('/^USE\s+blue_db;/mi', 'USE blue_test_db;', file_get_contents($seedPath));
            $this->streamIntoMysql($config, $seedSql);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('blue_test_db provisioned successfully from the repository source of truth.');

        return self::SUCCESS;
    }

    private function streamIntoMysql(array $config, string $sql): void
    {
        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->timeout(120)
            ->input($sql)
            ->run([
                'mysql',
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--user='.$config['username'],
                '--database=blue_test_db',
            ]);

        if (! $result->successful()) {
            throw new RuntimeException("mysql client failed: {$result->errorOutput()}");
        }
    }
}
