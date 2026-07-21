<?php
// database/factories/PlatformAuditLogFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAuditLogFactory extends Factory
{
    protected $model = PlatformAuditLog::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory(),
            'action' => 'suspend',
            'target_business_id' => Business::factory(),
            'metadata' => ['from_status' => 'active', 'to_status' => 'read_only'],
            'created_at' => now(),
        ];
    }
}
