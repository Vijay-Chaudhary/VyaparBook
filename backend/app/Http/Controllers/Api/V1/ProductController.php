<?php
// app/Http/Controllers/Api/V1/ProductController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Policies\CatalogPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            'name_hi' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'base_cost_per_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        // business_id is stamped by BelongsToTenant from app('tenant.id') — never
        // taken from the request, which is why it is not in the validated set.
        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        // findOrFail, not a manual tenant check: the tenant scope has already hidden other
        // tenants' rows, so this raises a genuine 404 rather than a 403 that
        // would confirm the row exists. See the catalog spec §6.
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'name_hi' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'base_cost_per_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->update($data);

        return response()->json($product->freshScoped());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $product = Product::findOrFail($id);

        // Archive, never delete: §9's ledger is append-only, so a two-year-old
        // sale must still resolve what was sold. archived_at is not fillable, so
        // it is assigned directly.
        $product->archived_at = Carbon::now();
        $product->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $product = Product::findOrFail($id);
        $product->archived_at = null;
        $product->save();

        return response()->json($product->freshScoped());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
