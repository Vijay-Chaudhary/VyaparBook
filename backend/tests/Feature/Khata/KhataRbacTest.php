<?php
// tests/Feature/Khata/KhataRbacTest.php
//
// The PRD §7 role matrix for the khata, in one place:
//
//   Capability          | owner | admin | salesman | accountant
//   create sale/return  |   ✓   |   ✓   |    ✓     |     —
//   void sale           |   ✓   |   ✓   |    —     |     —
//   record payment      |   ✓   |   ✓   |    ✓     |     ✓
//   reverse payment     |   ✓   |   ✓   |    —     |     —
//   view khata          |   ✓   |   ✓   |    ✓     |     ✓

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function rbacToken(Business $business, string $role): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function rbacCustomer(Business $business): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '500.00',
    ]);
}

function rbacPack(Business $business): ProductPack
{
    $product = Product::on('pgsql_migrate')->create(['business_id' => $business->id, 'name_hi' => 'सेव']);
    $packSize = PackSize::on('pgsql_migrate')->create(['business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500']);

    return ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $packSize->id, 'default_sell_price' => '90.00',
    ]);
}

/** Create a sale as an owner and return its id (a precondition for void tests). */
function ownerSaleId(Business $business, Customer $customer, ProductPack $pack): string
{
    return test()->withHeader('Authorization', 'Bearer ' . rbacToken($business, 'owner'))
        ->postJson('/api/v1/sales', [
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'sale_date' => '2026-07-17', 'lines' => [['product_pack_id' => $pack->id, 'qty' => 1]],
        ])->json('id');
}

function ownerPaymentId(Business $business, Customer $customer): string
{
    return test()->withHeader('Authorization', 'Bearer ' . rbacToken($business, 'owner'))
        ->postJson('/api/v1/payments', [
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'payment_date' => '2026-07-17', 'amount' => '100.00', 'mode' => 'cash',
        ])->json('id');
}

it('gates creating a sale to owner, admin and salesman', function () {
    $business = Business::factory()->create();
    $customer = rbacCustomer($business);
    $pack = rbacPack($business);

    foreach (['owner' => 201, 'admin' => 201, 'salesman' => 201, 'accountant' => 403] as $role => $status) {
        $this->withHeader('Authorization', 'Bearer ' . rbacToken($business, $role))
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
                'sale_date' => '2026-07-17', 'lines' => [['product_pack_id' => $pack->id, 'qty' => 1]],
            ])
            ->assertStatus($status);
    }
});

it('gates voiding a sale to owner and admin only', function () {
    $business = Business::factory()->create();
    $customer = rbacCustomer($business);
    $pack = rbacPack($business);

    foreach (['owner' => 201, 'admin' => 201, 'salesman' => 403, 'accountant' => 403] as $role => $status) {
        $saleId = ownerSaleId($business, $customer, $pack); // a fresh sale per role
        $this->withHeader('Authorization', 'Bearer ' . rbacToken($business, $role))
            ->postJson("/api/v1/sales/{$saleId}/void")
            ->assertStatus($status);
    }
});

it('lets every role record a payment', function () {
    $business = Business::factory()->create();
    $customer = rbacCustomer($business);

    foreach (['owner', 'admin', 'salesman', 'accountant'] as $role) {
        $this->withHeader('Authorization', 'Bearer ' . rbacToken($business, $role))
            ->postJson('/api/v1/payments', [
                'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
                'payment_date' => '2026-07-17', 'amount' => '100.00', 'mode' => 'cash',
            ])
            ->assertStatus(201);
    }
});

it('gates reversing a payment to owner and admin only', function () {
    $business = Business::factory()->create();
    $customer = rbacCustomer($business);

    foreach (['owner' => 201, 'admin' => 201, 'salesman' => 403, 'accountant' => 403] as $role => $status) {
        $paymentId = ownerPaymentId($business, $customer); // a fresh payment per role
        $this->withHeader('Authorization', 'Bearer ' . rbacToken($business, $role))
            ->postJson("/api/v1/payments/{$paymentId}/reverse")
            ->assertStatus($status);
    }
});

it('lets every role view the khata', function () {
    $business = Business::factory()->create();

    foreach (['owner', 'admin', 'salesman', 'accountant'] as $role) {
        $this->withHeader('Authorization', 'Bearer ' . rbacToken($business, $role))
            ->getJson('/api/v1/khata')
            ->assertOk();
    }
});
