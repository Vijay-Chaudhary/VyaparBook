<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data: the platform superadmin, then the one real
     * tenant.
     *
     * Writes go through the privileged pgsql_migrate connection: the seeder
     * runs outside a request, so no SetTenantContext transaction has set
     * app.current_tenant, and the memberships RLS WITH CHECK would reject the
     * insert on the restricted app connection.
     */
    public function run(): void
    {
        $this->platformAdmin();

        $this->call(ShreeRajShyamajiSeeder::class);

        $this->command->info('Seeded owner@vyaparbook.test / password123');
        $this->command->info('Superadmin: admin@vyaparbook.test / password123');
    }

    /**
     * The admin console needs a superadmin to log into. Infrastructure rather
     * than demo business data, which is why it outlived DemoDataSeeder.
     */
    private function platformAdmin(): void
    {
        $admin = User::on('pgsql_migrate')->updateOrCreate(
            ['email' => 'admin@vyaparbook.test'],
            ['name' => 'Platform Admin', 'phone' => '9800000000', 'password' => Hash::make('password123')],
        );

        $admin->setConnection('pgsql_migrate');
        $admin->is_platform_admin = true;
        $admin->save();
    }
}
