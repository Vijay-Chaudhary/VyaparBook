<?php
// database/migrations/2026_07_05_000004_create_memberships_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'salesman', 'accountant']);
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
