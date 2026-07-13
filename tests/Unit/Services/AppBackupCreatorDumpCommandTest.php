<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AppBackup\AppBackupCreator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * P2-35: the platform self-backup must pg_dump against the DIRECT Postgres
 * connection, not the pooled PgBouncer one — transaction pooling breaks pg_dump
 * (it needs a real session). It must also pass the direct host/port/user/db and
 * the password via PGPASSWORD (never a hardcoded value, never logged).
 */
class AppBackupCreatorDumpCommandTest extends TestCase
{
    private AppBackupCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = new AppBackupCreator;

        // Distinct direct vs pooled endpoints so we can prove which one is used.
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => 'pgbouncer',
            'port' => '6432',
            'username' => 'pooled_user',
            'password' => 'pooled_secret',
            'database' => 'simplead',
        ]);
        Config::set('database.connections.pgsql_direct', [
            'driver' => 'pgsql',
            'host' => 'pgsql-direct',
            'port' => '5432',
            'username' => 'direct_user',
            'password' => 'direct_secret',
            'database' => 'simplead',
        ]);
    }

    private function resolveConfig(): array
    {
        $method = new \ReflectionMethod($this->creator, 'databaseConnectionConfig');

        return $method->invoke($this->creator, 'pgsql');
    }

    private function buildCommand(): string
    {
        $method = new \ReflectionMethod($this->creator, 'buildDumpCommand');

        return $method->invoke($this->creator, 'pgsql', $this->resolveConfig(), '/tmp/database.sql');
    }

    public function test_resolves_the_direct_connection_for_postgres(): void
    {
        $config = $this->resolveConfig();

        $this->assertSame('pgsql-direct', $config['host']);
        $this->assertSame('5432', $config['port']);
    }

    public function test_pg_dump_command_targets_the_direct_host_and_port(): void
    {
        $cmd = $this->buildCommand();

        $this->assertStringContainsString('pg_dump', $cmd);
        $this->assertStringContainsString("--host='pgsql-direct'", $cmd);
        $this->assertStringContainsString("--port='5432'", $cmd);
        $this->assertStringContainsString("--username='direct_user'", $cmd);
        $this->assertStringContainsString("PGPASSWORD='direct_secret'", $cmd);

        // Must NOT touch the pooled PgBouncer endpoint.
        $this->assertStringNotContainsString('pgbouncer', $cmd);
        $this->assertStringNotContainsString('6432', $cmd);
        $this->assertStringNotContainsString('pooled_secret', $cmd);
    }

    public function test_falls_back_to_pooled_config_when_direct_is_unconfigured(): void
    {
        Config::set('database.connections.pgsql_direct', null);

        $method = new \ReflectionMethod($this->creator, 'databaseConnectionConfig');
        $config = $method->invoke($this->creator, 'pgsql');

        $this->assertSame('pgbouncer', $config['host']);
    }
}
