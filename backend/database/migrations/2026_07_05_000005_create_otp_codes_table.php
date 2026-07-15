<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);
            $table->string('code_hash', 128);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('otp_codes');
    }
};
