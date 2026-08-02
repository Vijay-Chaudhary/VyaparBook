<?php
// tests/Unit/CatalogTemplateServiceTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Services\CatalogTemplateService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

it('lists the templates on disk plus blank', function () {
    $available = CatalogTemplateService::available();

    expect($available)->toContain('blank', 'namkeen', 'sweets', 'spices');
});

it('seeds the namkeen template into one business', function () {
    $business = Business::factory()->create();

    // Mirror what a request does: a transaction with the tenant bound, so the
    // scope admits the inserts and stamps business_id on them.
    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('namkeen', $business->id);
    });

    expect(Product::where('business_id', $business->id)->count())->toBe(3);
    expect(PackSize::where('business_id', $business->id)->count())->toBe(8);
    expect(ProductPack::where('business_id', $business->id)->count())->toBe(12);
});

it('fills each pack cost from the product base cost per kg', function () {
    $business = Business::factory()->create();

    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('namkeen', $business->id);
    });

    $sev = Product::where('business_id', $business->id)->where('name_en', 'Sev')->first();
    $oneKg = PackSize::where('business_id', $business->id)->where('label', '1kg')->first();
    $pack = ProductPack::where('product_id', $sev->id)->where('pack_size_id', $oneKg->id)->first();

    // Sev base_cost_per_kg 130.00 × 1.000 kg
    expect($pack->default_cost_price)->toBe('130.00');
});

it('inserts nothing for the blank template', function () {
    $business = Business::factory()->create();

    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('blank', $business->id);
    });

    expect(Product::where('business_id', $business->id)->count())->toBe(0);
});

it('rejects an unknown template', function () {
    $business = Business::factory()->create();

    expect(fn () => app(CatalogTemplateService::class)->apply('nonexistent', $business->id))
        ->toThrow(InvalidArgumentException::class);
});
