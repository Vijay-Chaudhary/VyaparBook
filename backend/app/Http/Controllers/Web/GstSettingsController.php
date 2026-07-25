<?php
// app/Http/Controllers/Web/GstSettingsController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GST reference data: the shop default rate and state code, plus HSN and rate
 * per product (PRD Phase 3).
 *
 * Blade rather than the React catalog on purpose — the GST columns are
 * server-side only, so setting them here leaves the offline sync payload and
 * the Dexie schema untouched.
 */
class GstSettingsController extends Controller
{
    use ResolvesOwnedTenant;

    public function edit(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$business, $products] = $this->runInTenant($businessId, fn () => [
            Business::findOrFail($businessId),
            Product::query()->whereNull('archived_at')->orderBy('name_en')->get(),
        ]);

        return view('gst.index', [
            'businessId' => $businessId,
            'business' => $business,
            'products' => $products,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'default_gst_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'state_code' => ['nullable', 'string', 'size:2'],
            'products' => ['nullable', 'array'],
            'products.*.hsn_code' => ['nullable', 'string', 'max:8'],
            'products.*.gst_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->runInTenant($businessId, function () use ($businessId, $data) {
            $business = Business::findOrFail($businessId);
            $business->default_gst_rate_percent = $data['default_gst_rate_percent'] ?? null;
            $business->state_code = $data['state_code'] ?? null;
            $business->save();

            foreach ($data['products'] ?? [] as $productId => $fields) {
                $product = Product::query()->where('business_id', $businessId)->find($productId);

                if ($product === null) {
                    continue;   // a stale form field is not worth failing the save
                }

                // Assigned explicitly: these columns are absent from $fillable so
                // they can never arrive through the offline sync payload.
                $product->hsn_code = $fields['hsn_code'] ?? null;
                $product->gst_rate_percent = $fields['gst_rate_percent'] ?? null;
                $product->save();
            }
        });

        return redirect()->route('gst', ['business' => $businessId])->with('status', __('gst.saved'));
    }
}
