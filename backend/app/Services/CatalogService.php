<?php
// app/Services/CatalogService.php

namespace App\Services;

use App\Models\PackSize;
use App\Models\Product;

class CatalogService
{
    /**
     * Suggest a pack's cost from the product's per-kg base cost.
     *
     * Only ever fills a blank at creation time — default_cost_price is stored
     * per pack and authoritative once set, because packaging and labour do not
     * scale linearly with weight (a 100g pouch genuinely costs more per kg than
     * a 1kg bag). See the catalog spec §5.
     *
     * bcmul, not float arithmetic: rupees must not drift. It truncates at the
     * given scale rather than rounding, which cannot overstate a cost.
     */
    public function suggestedCostPrice(Product $product, PackSize $pack): ?string
    {
        if ($product->base_cost_per_kg === null) {
            return null;
        }

        return bcmul((string) $product->base_cost_per_kg, (string) $pack->weight_kg, 2);
    }
}
