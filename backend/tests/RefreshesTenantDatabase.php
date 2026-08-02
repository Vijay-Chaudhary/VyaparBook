<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resets the test database between tests without wrapping each test in a
 * transaction.
 *
 * Laravel's RefreshDatabase wraps each test in a transaction and rolls back.
 * This suite instead migrates once per run and clears the tables between tests,
 * so every write genuinely commits.
 *
 * That mattered enormously under Postgres, where setup code wrote through a
 * privileged second connection to bypass RLS and an outer transaction would
 * have hidden those rows. With MySQL there is one connection and no RLS, so the
 * historical reason is gone — but the behaviour is kept because ~900 tests rely
 * on writes being visible across connections and on SetTenantContext's own
 * transaction not nesting inside a test transaction.
 */
trait RefreshesTenantDatabase
{
    protected function setUpRefreshesTenantDatabase(): void
    {
        if (! TenantDatabaseState::$migrated) {
            Artisan::call('migrate:fresh', [
                '--force' => true,
            ]);

            TenantDatabaseState::$migrated = true;
            TenantDatabaseState::$tables = null;
            TenantDatabaseState::$autoIncrementTables = null;
        }

        $this->truncateTenantTables();
    }

    protected function truncateTenantTables(): void
    {
        [$tables, $autoIncrement] = $this->schemaShape();

        if ($tables === []) {
            return;
        }

        // Postgres cleared these with one TRUNCATE ... RESTART IDENTITY CASCADE.
        // MySQL's TRUNCATE has no CASCADE and refuses to run on a table another
        // table's foreign key points at, so the checks come off for the sweep.
        // Session-scoped, and this connection only ever serves the test run.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                // DELETE, not TRUNCATE. MySQL's TRUNCATE is DDL — it drops and
                // recreates the table — which costs ~6.2s across these 42 tables
                // and would have added over an hour to a full run. DELETE on
                // mostly-empty tables costs ~11ms.
                $deleted = DB::delete("DELETE FROM `{$table}`");

                // The one thing TRUNCATE gave for free: DELETE leaves
                // AUTO_INCREMENT where it was, so ids would climb across tests
                // and anything asserting on a specific id would drift. Reset
                // only the four tables that have such a column, and only when
                // this test actually put rows in them.
                if ($deleted > 0 && isset($autoIncrement[$table])) {
                    DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The tables to sweep, and which of them carry an AUTO_INCREMENT column.
     *
     * Cached for the whole run: the schema cannot change between tests, and
     * these two information_schema lookups cost ~250ms cold.
     *
     * @return array{0: list<string>, 1: array<string, true>}
     */
    private function schemaShape(): array
    {
        if (TenantDatabaseState::$tables === null) {
            TenantDatabaseState::$tables = array_values(array_filter(
                array_column(Schema::getTables(), 'name'),
                fn (string $name) => $name !== 'migrations'
            ));

            TenantDatabaseState::$autoIncrementTables = array_fill_keys(
                array_column(DB::select(
                    'SELECT table_name AS n FROM information_schema.columns
                     WHERE table_schema = database() AND extra LIKE ?',
                    ['%auto_increment%']
                ), 'n'),
                true
            );
        }

        return [TenantDatabaseState::$tables, TenantDatabaseState::$autoIncrementTables];
    }
}
