<?php
// tests/Feature/Stock/StockProductionRbacTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

// The whole-module gate: stock & production is owner/admin ONLY — salesman and
// accountant have no access at all, reads included (PRD §7). This is the one
// place that rule is proven end to end, across every stock/production endpoint.

function rbacToken(Business $business, string $role): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

/**
 * @return array{0: Business, 1: RawMaterial, 2: Product}
 */
function rbacFixtures(): array
{
    $business = Business::factory()->create();
    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);

    return [$business, $material, $product];
}

// [method, url builder, body builder] for every stock/production endpoint.
dataset('stock endpoints', [
    'raw-material create' => [
        'post',
        fn (RawMaterial $m, Product $p) => '/api/v1/raw-materials',
        fn (RawMaterial $m, Product $p) => ['uuid' => (string) Str::uuid(), 'name' => 'Oil', 'unit' => 'litre'],
    ],
    'stock-movement record' => [
        'post',
        fn (RawMaterial $m, Product $p) => '/api/v1/stock-movements',
        fn (RawMaterial $m, Product $p) => [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $m->id,
            'movement_date' => '2026-07-15', 'kind' => 'in', 'qty' => '10.000',
        ],
    ],
    'production create' => [
        'post',
        fn (RawMaterial $m, Product $p) => '/api/v1/production',
        fn (RawMaterial $m, Product $p) => [
            'uuid' => (string) Str::uuid(), 'product_id' => $p->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $m->id, 'qty' => '5.000']],
        ],
    ],
    'stock summary read' => [
        'get',
        fn (RawMaterial $m, Product $p) => '/api/v1/stock',
        fn (RawMaterial $m, Product $p) => null,
    ],
    'production log read' => [
        'get',
        fn (RawMaterial $m, Product $p) => '/api/v1/production',
        fn (RawMaterial $m, Product $p) => null,
    ],
]);

it('lets owner and admin reach every stock/production endpoint', function (string $method, Closure $url, Closure $body) {
    foreach (['owner', 'admin'] as $role) {
        [$business, $material, $product] = rbacFixtures();
        $token = rbacToken($business, $role);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->json($method, $url($material, $product), $body($material, $product) ?? []);

        expect($response->status())->not->toBe(403); // 200/201 — never forbidden
    }
})->with('stock endpoints');

it('forbids salesman and accountant on every stock/production endpoint', function (string $method, Closure $url, Closure $body) {
    foreach (['salesman', 'accountant'] as $role) {
        [$business, $material, $product] = rbacFixtures();
        $token = rbacToken($business, $role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->json($method, $url($material, $product), $body($material, $product) ?? [])
            ->assertStatus(403);
    }
})->with('stock endpoints');
