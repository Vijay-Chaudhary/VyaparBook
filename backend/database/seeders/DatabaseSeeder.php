<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data: the platform superadmin, then the one real
     * tenant.
     *
     * Runs inside withoutTenant() because a seeder has no request and so no
     * bound tenant — one of the four sanctioned cross-tenant paths.
     */
    public function run(): void
    {
        Tenancy::withoutTenant(function () {
            $this->platformAdmin();
            $this->call(ShreeRajShyamajiSeeder::class);
        });

        $this->command->info('Seeded owner@vyaparbook.test / password123');
        $this->command->info('Superadmin: admin@vyaparbook.test / password123');
    }

    /**
     * The admin console needs a superadmin to log into. Infrastructure rather
     * than demo business data, which is why it outlived DemoDataSeeder.
     */
    private function platformAdmin(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@vyaparbook.test'],
            ['name' => 'Platform Admin', 'phone' => '9800000000', 'password' => Hash::make('password123')],
        );

        $admin->is_platform_admin = true;
        $admin->save();
    }
}
