<?php
// app/Http/Controllers/Web/PricingController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Costs and default selling prices (Phase 2 of the owner control plan).
 *
 * Catalog CRUD already existed on the JSON API; there was no screen anywhere
 * that reached it, so changing a cost needed a developer. That is why this
 * shop's stored costs (₹62/₹87/₹73 per kg) were nowhere near its real ones
 * (₹93/₹130/₹117).
 *
 * Owner-only, matching every other configuration screen (GST, reminders,
 * beats). Order acceptance is the one screen that admits admins, because that
 * is a daily operational job; setting cost is not.
 */
class PricingController extends Controller
{
    use ResolvesOwnedTenant;

    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $products = $this->runInTenant(
            $businessId,
            fn () => Product::query()
                ->whereNull('archived_at')
                ->with(['productPacks' => fn ($q) => $q->whereNull('archived_at')->with(['product', 'packSize'])])
                ->orderBy('name_en')
                ->get()
        );

        return view('pricing.index', [
            'businessId' => $businessId,
            'products' => $products,
        ]);
    }

    /**
     * Save whatever was typed: per-product per-kg cost, and per-pack cost and
     * selling price.
     *
     * A blank pack cost is stored as NULL rather than zero, which is the whole
     * point of the field: null means "derive from the per-kg figure", and zero
     * would mean "this pack is free" and silently drop the floor to nothing.
     */
    public function update(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'products' => ['nullable', 'array'],
            'products.*.base_cost_per_kg' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'packs' => ['nullable', 'array'],
            'packs.*.default_cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'packs.*.default_sell_price' => ['required_with:packs', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        $this->runInTenant($businessId, function () use ($data) {
            foreach ($data['products'] ?? [] as $id => $fields) {
                $product = Product::query()->whereNull('archived_at')->find($id);

                // Silently skip rather than 404: an archived or foreign id in a
                // stale form must not lose the rest of a good submission.
                if ($product === null) {
                    continue;
                }

                $product->base_cost_per_kg = self::blankToNull($fields['base_cost_per_kg'] ?? null);
                $product->save();
            }

            foreach ($data['packs'] ?? [] as $id => $fields) {
                $pack = ProductPack::query()->whereNull('archived_at')->find($id);

                if ($pack === null) {
                    continue;
                }

                $pack->default_cost_price = self::blankToNull($fields['default_cost_price'] ?? null);
                $pack->default_sell_price = $fields['default_sell_price'];
                $pack->save();
            }
        });

        return redirect()->route('pricing', ['business' => $businessId])
            ->with('status', __('pricing.saved'));
    }

    /**
     * Set every pack's cost for one product from its per-kg figure.
     *
     * This exists because of a trap that would otherwise make the screen lie:
     * PriceFloor reads `default_cost_price` FIRST and only falls back to
     * `base_cost_per_kg × weight`. Every pack in this shop has a per-pack cost
     * set, so typing a new per-kg cost on its own changes nothing at all — an
     * owner would enter ₹93 and watch every floor stay where it was.
     *
     * Rounded UP to the paisa, matching what PriceFloor does when it derives a
     * floor itself. CatalogService::suggestedCostPrice truncates instead, which
     * is right for suggesting a blank at creation but would quietly set every
     * stored floor a paisa BELOW true cost here.
     */
    public function recost(Request $request, string $product): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $applied = $this->runInTenant($businessId, function () use ($product) {
            $model = Product::query()->whereNull('archived_at')
                ->with(['productPacks' => fn ($q) => $q->whereNull('archived_at')->with(['product', 'packSize'])])
                ->find($product);

            if ($model === null || $model->base_cost_per_kg === null) {
                return null;
            }

            $count = 0;

            foreach ($model->productPacks as $pack) {
                $weight = $pack->packSize?->weight_kg;

                if ($weight === null) {
                    continue;
                }

                $pack->default_cost_price = self::ceilToPaisa(
                    bcmul((string) $model->base_cost_per_kg, (string) $weight, 6)
                );
                $pack->save();
                $count++;
            }

            return $count;
        });

        return redirect()->route('pricing', ['business' => $businessId])->with(
            $applied === null ? 'error' : 'status',
            $applied === null
                ? __('pricing.recost_needs_per_kg')
                : __('pricing.recosted', ['count' => $applied])
        );
    }

    /**
     * '' from an emptied number field means "no cost basis", not zero. Zero is
     * a real, different answer — a free issue — and PriceFloor honours it.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /** Mirrors PriceFloor::ceilToPaisa, so a stored cost never sits below true cost. */
    private static function ceilToPaisa(string $value): string
    {
        $truncated = bcadd($value, '0', 2);

        return bccomp($truncated, $value, 6) === 0 ? $truncated : bcadd($truncated, '0.01', 2);
    }
}
