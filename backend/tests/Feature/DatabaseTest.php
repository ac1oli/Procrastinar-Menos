<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_on_isolated_postgres_test_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('procrastinar_menos_test', DB::connection()->getDatabaseName());
    }

    public function test_pgvector_extension_is_available(): void
    {
        $version = DB::scalar("select extversion from pg_extension where extname = 'vector'");
        $this->assertNotNull($version);

        $distance = DB::scalar("select '[1,0,0]'::vector <=> '[0,1,0]'::vector");
        $this->assertEqualsWithDelta(1.0, (float) $distance, 1e-6);
    }
}
