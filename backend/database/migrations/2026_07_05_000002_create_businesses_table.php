<?php
// database/migrations/2026_07_05_000002_create_businesses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('city', 80)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('default_language', 8)->default('hi');
            $table->string('plan', 20)->default('trial');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('businesses');
    }
};
