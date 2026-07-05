<?php
// database/migrations/2026_07_05_000001_create_app_role.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'vyaparbook_app') THEN
                    CREATE ROLE vyaparbook_app LOGIN;
                END IF;
            END
            $$;
        SQL);

        DB::connection('pgsql_migrate')->statement(
            'GRANT CONNECT ON DATABASE ' . config('database.connections.pgsql_migrate.database') . ' TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement('GRANT USAGE ON SCHEMA public TO vyaparbook_app');
        DB::connection('pgsql_migrate')->statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO vyaparbook_app'
        );
    }

    public function down(): void
    {
        // Intentionally left as a no-op: dropping the role would break the app's
        // connection immediately, and other tables may still depend on grants to it.
    }
};
