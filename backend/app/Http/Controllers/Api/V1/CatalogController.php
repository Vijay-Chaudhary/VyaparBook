<?php
// app/Http/Controllers/Api/V1/CatalogController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Models\Product;
use App\Policies\CatalogPolicy;
use App\Services\CatalogTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogTemplateService $templateService) {}

    /**
     * The whole tenant catalog in one response. Readable by every role: a
     * salesman cannot sell without it and an accountant needs it to read a khata.
     */
    public function index(Request $request)
    {
        $includeArchived = $request->boolean('include_archived');

        $products = Product::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->with(['productPacks' => function ($q) use ($includeArchived) {
                $q->with('packSize');

                // Effective archiving: hide a pack whose own row, or whose pack
                // size, is archived. The product's own state is already handled
                // by the outer query.
                $q->unless($includeArchived, function ($q) {
                    $q->whereNull('product_packs.archived_at')
                        ->whereHas('packSize', fn ($p) => $p->whereNull('pack_sizes.archived_at'));
                });
            }])
            ->orderBy('name_en')
            ->get();

        $packSizes = PackSize::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('weight_kg')
            ->get();

        return response()->json([
            // name_hi/name_en are returned raw rather than pre-resolved to a
            // display string: which one to show is the client's decision, driven
            // by the business's language, falling back to name_hi when name_en is
            // absent. Choosing server-side would bake a language into the API.
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name_hi' => $product->name_hi,
                'name_en' => $product->name_en,
                'base_cost_per_kg' => $product->base_cost_per_kg,
                'archived_at' => $product->archived_at,
                'version' => $product->version,
                'packs' => $product->productPacks->map(fn ($pack) => [
                    'id' => $pack->id,
                    'pack_size_id' => $pack->pack_size_id,
                    'label' => $pack->packSize->label,
                    'weight_kg' => $pack->packSize->weight_kg,
                    'in_dropdown' => $pack->packSize->in_dropdown,
                    'default_sell_price' => $pack->default_sell_price,
                    'default_cost_price' => $pack->default_cost_price,
                    'archived_at' => $pack->archived_at,
                    'version' => $pack->version,
                ])->values(),
            ])->values(),

            // Pack sizes with in_dropdown = false ARE included. The flag is a
            // rendering hint the client applies to the sale screen's dropdown,
            // not a filter on the payload — those sizes are still sellable and
            // the offline cache must hold them.
            'pack_sizes' => $packSizes->map(fn (PackSize $packSize) => [
                'id' => $packSize->id,
                'label' => $packSize->label,
                'weight_kg' => $packSize->weight_kg,
                'in_dropdown' => $packSize->in_dropdown,
                'archived_at' => $packSize->archived_at,
                'version' => $packSize->version,
            ])->values(),
        ]);
    }

    public function seed(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return response()->json(
                ['message' => 'Only owners and admins can manage the catalog.'],
                403
            );
        }

        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(CatalogTemplateService::available())],
        ]);

        // Seeding is guarded, not idempotent. Without this, a second seed hits
        // the pack_sizes unique index and surfaces a raw constraint violation as
        // a 500. PRD §5 frames this as a one-time onboarding step; a 409 states
        // that rule, a duplicate-key crash does not.
        if (Product::query()->exists()) {
            return response()->json([
                'message' => 'Catalog is not empty. Seeding is a one-time onboarding step.',
            ], 409);
        }

        $this->templateService->apply($data['template'], app('tenant.id'));

        return response()->json(['message' => 'Catalog seeded.'], 201);
    }
}
