<?php
// database/migrations/2026_07_17_000001_create_sync_seq_sequence.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One global sequence, not one per tenant: it only has to be monotonic,
        // never gap-free, and RLS narrows every delta pull to a single tenant's
        // rows. HasSyncSequence stamps nextval() onto every syncable row on save,
        // and pull resumes from the max value it last returned.
        //
        // No explicit GRANT is needed: create_app_role ran
        //   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES
        // so a sequence created here by the same privileged role is already usable
        // by the restricted vyaparbook_app role — same mechanism the catalog tables
        // rely on for their table grants.
        //
        // IF NOT EXISTS because a standalone sequence is not a table: migrate:fresh
        // (db:wipe) drops tables but leaves this sequence in place, so re-running
        // this migration would otherwise fail with "relation already exists". A
        // surviving sequence is harmless — a cursor only needs monotonicity, so
        // carrying its value across a fresh is fine.
        DB::connection('pgsql_migrate')->statement('CREATE SEQUENCE IF NOT EXISTS sync_seq_global');
    }

    public function down(): void
    {
        DB::connection('pgsql_migrate')->statement('DROP SEQUENCE IF EXISTS sync_seq_global');
    }
};
