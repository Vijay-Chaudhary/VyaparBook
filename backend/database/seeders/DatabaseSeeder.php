<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds a known owner + business + membership for local development.
     *
     * Writes go through the privileged pgsql_migrate connection: the seeder runs
     * outside a request, so no SetTenantContext transaction has set
     * app.current_tenant, and the memberships RLS WITH CHECK would reject the
     * insert on the restricted app connection.
     */
    public function run(): void
    {
        $user = User::on('pgsql_migrate')->updateOrCreate(
            ['email' => 'owner@vyaparbook.test'],
            [
                'name' => 'Test Owner',
                'phone' => '9876500001',
                'password' => Hash::make('password123'),
            ]
        );

        $business = Business::on('pgsql_migrate')->updateOrCreate(
            ['name' => 'Shree Raj Shyama Ji Namkeen'],
            [
                'city' => 'Hata',
                'default_language' => 'hi',
                'plan' => 'trial',
            ]
        );

        Membership::on('pgsql_migrate')->updateOrCreate(
            ['user_id' => $user->id, 'business_id' => $business->id],
            ['role' => 'owner']
        );

        $this->command->info("Seeded owner@vyaparbook.test / password123 (business: {$business->id})");

        // Rich, all-module demo data (catalog, khata, stock, production, billing,
        // consent, platform admin) for local development.
        $this->call(DemoDataSeeder::class);
    }
}
