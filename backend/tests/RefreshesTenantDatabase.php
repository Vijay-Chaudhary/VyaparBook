<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Resets the test database between tests without wrapping each test in a
 * transaction.
 *
 * Laravel's RefreshDatabase is unusable under this project's tenancy design for
 * three reasons:
 *
 *  1. It issues its `drop table` over the default `pgsql` connection, whose
 *     restricted `vyaparbook_app` role does not own the tables (migrations
 *     create them via the privileged `pgsql_migrate` connection).
 *  2. It wraps each test in a transaction on `pgsql`. Rows written through
 *     `pgsql_migrate` — which setup code must use to bypass RLS — live in a
 *     separate session that cannot see those uncommitted rows, so foreign keys
 *     to them fail.
 *  3. `SET LOCAL` is scoped to the enclosing transaction. An outer test
 *     transaction would keep SetTenantContext's GUCs alive across the whole
 *     test instead of one request, silently weakening the isolation the RLS
 *     tests exist to prove.
 *
 * So: migrate once per run, then TRUNCATE as the privileged role between tests
 * and let every write genuinely commit.
 */
trait RefreshesTenantDatabase
{
    protected function setUpRefreshesTenantDatabase(): void
    {
        if (! TenantDatabaseState::$migrated) {
            Artisan::call('migrate:fresh', [
                '--database' => 'pgsql_migrate',
                '--force' => true,
            ]);

            TenantDatabaseState::$migrated = true;
        }

        $this->truncateTenantTables();
    }

    protected function truncateTenantTables(): void
    {
        $tables = DB::connection('pgsql_migrate')->select(
            "select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'"
        );

        if ($tables === []) {
            return;
        }

        $quoted = implode(', ', array_map(
            fn ($table) => '"' . $table->tablename . '"',
            $tables
        ));

        DB::connection('pgsql_migrate')->statement(
            "TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE"
        );
    }
}
