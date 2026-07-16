<?php
// app/Services/CatalogTemplateService.php

namespace App\Services;

use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use InvalidArgumentException;

class CatalogTemplateService
{
    /**
     * "Blank" is a first-class choice from PRD §5's onboarding step, not the
     * absence of one: it has no file and inserts nothing.
     */
    public const BLANK = 'blank';

    public function __construct(private readonly CatalogService $catalogService) {}

    /**
     * Template slugs a caller may pick, derived from the files on disk so adding
     * a vertical never means editing a hardcoded list.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $files = glob(database_path('catalog_templates/*.php')) ?: [];

        return array_merge(
            [self::BLANK],
            array_map(fn (string $path) => basename($path, '.php'), $files)
        );
    }

    /**
     * Insert a template's rows for one business.
     *
     * Runs inside the caller's transaction — SetTenantContext has already opened
     * one and set app.current_tenant, so BelongsToTenant stamps business_id and
     * the RLS WITH CHECK passes. business_id is still passed explicitly here so
     * the service is callable from a console command or seeder that has no
     * request context.
     */
    public function apply(string $slug, string $businessId): void
    {
        if ($slug === self::BLANK) {
            return;
        }

        $path = database_path("catalog_templates/{$slug}.php");

        if (! file_exists($path)) {
            throw new InvalidArgumentException("Unknown catalog template [{$slug}].");
        }

        $template = require $path;

        $products = [];
        foreach ($template['products'] as $key => $attributes) {
            $products[$key] = Product::create($attributes + ['business_id' => $businessId]);
        }

        $packSizes = [];
        foreach ($template['pack_sizes'] as $key => $attributes) {
            $packSizes[$key] = PackSize::create($attributes + ['business_id' => $businessId]);
        }

        foreach ($template['product_packs'] as $row) {
            $product = $products[$row['product']];
            $packSize = $packSizes[$row['pack']];

            ProductPack::create([
                'business_id' => $businessId,
                'product_id' => $product->id,
                'pack_size_id' => $packSize->id,
                'default_sell_price' => $row['default_sell_price'],
                'default_cost_price' => $row['default_cost_price']
                    ?? $this->catalogService->suggestedCostPrice($product, $packSize),
            ]);
        }
    }
}
