<?php
// database/migrations/2026_07_15_000002_create_pack_sizes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('pack_sizes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('label', 40);
            $table->decimal('weight_kg', 8, 3);
            $table->boolean('in_dropdown')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Also serves as the business_id index (leftmost column).
            $table->unique(['business_id', 'label']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE pack_sizes ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE pack_sizes FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY pack_sizes_isolation ON pack_sizes
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('pack_sizes');
    }
};
