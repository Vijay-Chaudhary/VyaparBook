<?php
// database/migrations/2026_07_21_000001_default_language_to_en.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default UI language is now English (APP_LOCALE=en). A shop created without
 * an explicit default_language — the API path, when a client omits it — should
 * follow that default rather than the original Hindi. The Blade onboarding path
 * already passes app()->getLocale() explicitly, so this aligns the column with it.
 *
 * Only the column DEFAULT changes; existing rows keep whatever they were set to,
 * so no shop already on Hindi is silently switched. Raw ALTER (no doctrine/dbal
 * needed) since only the default is being changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pgsql_migrate runs as the schema-owning role. The default `pgsql`
        // connection is the least-privilege app role (vyaparbook_app), which is
        // deliberately NOT the owner of `businesses`, so an ALTER TABLE there
        // fails with "must be owner of table businesses". Every other schema
        // migration in this repo uses pgsql_migrate for exactly this reason.
        DB::connection('pgsql_migrate')->statement("ALTER TABLE businesses ALTER COLUMN default_language SET DEFAULT 'en'");
    }

    public function down(): void
    {
        DB::connection('pgsql_migrate')->statement("ALTER TABLE businesses ALTER COLUMN default_language SET DEFAULT 'hi'");
    }
};
