<?php
// tests/Feature/Catalog/CatalogArchiveTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;

function archiveToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

function archiveFixture(Business $business): array
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => '80.00',
    ]);

    return [$product, $packSize, $productPack];
}

it('drops an archived product from the catalog', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('returns an archived product under include_archived', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog?include_archived=1')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $product->id);
});

it('keeps an archived product resolvable by id so old sales still work', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    expect(Product::on('pgsql_migrate')->find($product->id))->not->toBeNull();
});

it('restores an archived product', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/restore")
        ->assertOk()
        ->assertJson(['archived_at' => null]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(1, 'products');
});

it('hides a product packs without writing archived_at on them', function () {
    $business = Business::factory()->create();
    [$product, , $productPack] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    // The pack vanishes from the read...
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(0, 'products');

    // ...but its own row is untouched. This is what makes restore lossless.
    expect(ProductPack::on('pgsql_migrate')->find($productPack->id)->archived_at)->toBeNull();
});

it('restores only the packs that were not individually archived', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $keep = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $drop = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $keptPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $keep->id, 'default_sell_price' => '80.00',
    ]);
    $archivedPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $drop->id, 'default_sell_price' => '150.00',
    ]);
    $token = archiveToken($business);

    // Archive one pack individually, then the whole product, then restore it.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/product-packs/{$archivedPack->id}")->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/restore")->assertOk();

    // The individually-archived pack must stay gone — the information survived.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonCount(1, 'products.0.packs');

    expect($response->json('products.0.packs.0.id'))->toBe($keptPack->id);
});

it('hides a pack whose pack size is archived', function () {
    $business = Business::factory()->create();
    [, $packSize] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/pack-sizes/{$packSize->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonCount(0, 'products.0.packs')
        ->assertJsonCount(0, 'pack_sizes');
});
