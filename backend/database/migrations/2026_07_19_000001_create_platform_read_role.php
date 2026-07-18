<?php
// database/migrations/2026_07_19_000001_create_platform_read_role.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A least-privilege, SELECT-only role that BYPASSes RLS, for the platform
 * (Superadmin) console's cross-tenant reads. It can read every tenant's rows but
 * cannot mutate anything — writes stay on the normal RLS-scoped connection.
 *
 * Runs on pgsql_migrate (the superuser): only a superuser may grant BYPASSRLS.
 * Idempotent so migrate:fresh across runs never fails on an existing role.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_migrate')->unprepared(<<<'SQL'
            DO $$
            BEGIN
              IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'vyapar_platform_ro') THEN
                CREATE ROLE vyapar_platform_ro LOGIN PASSWORD 'platform_ro_pw' BYPASSRLS;
              END IF;
            END
            $$;
            GRANT USAGE ON SCHEMA public TO vyapar_platform_ro;
            GRANT SELECT ON ALL TABLES IN SCHEMA public TO vyapar_platform_ro;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO vyapar_platform_ro;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_migrate')->unprepared(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'vyapar_platform_ro') THEN
                DROP OWNED BY vyapar_platform_ro;
                DROP ROLE vyapar_platform_ro;
              END IF;
            END
            $$;
        SQL);
    }
};
