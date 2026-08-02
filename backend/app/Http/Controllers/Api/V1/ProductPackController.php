<?php
// app/Http/Controllers/Api/V1/ProductPackController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Policies\CatalogPolicy;
use App\Services\CatalogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductPackController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService) {}

    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            // The business_id clause is what makes an id from another business
            // "not exist" here, failing with a 422 rather than pairing across
            // tenants. It is NOT redundant with the tenant scope: exists rules
            // resolve through the presence verifier, which builds a raw query
            // builder that Eloquent's global scope never reaches. Postgres RLS
            // used to narrow it regardless; MySQL does not.
            'product_id' => [
                'required', 'uuid',
                Rule::exists('products', 'id')->where('business_id', app('tenant.id')),
            ],
            'pack_size_id' => [
                'required', 'uuid',
                Rule::exists('pack_sizes', 'id')->where('business_id', app('tenant.id')),
            ],
            'default_sell_price' => ['required', 'numeric', 'min:0'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $packSize = PackSize::findOrFail($data['pack_size_id']);

        $productPack = ProductPack::create([
            'product_id' => $product->id,
            'pack_size_id' => $packSize->id,
            'default_sell_price' => $data['default_sell_price'],
            // Suggest from the per-kg base cost only when the caller left it
            // blank. Once set, the per-pack figure is authoritative.
            'default_cost_price' => $data['default_cost_price']
                ?? $this->catalogService->suggestedCostPrice($product, $packSize),
        ]);

        return response()->json($productPack, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);

        $data = $request->validate([
            'default_sell_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $productPack->update($data);

        return response()->json($productPack->freshScoped());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);
        $productPack->archived_at = Carbon::now();
        $productPack->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);
        $productPack->archived_at = null;
        $productPack->save();

        return response()->json($productPack->freshScoped());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
