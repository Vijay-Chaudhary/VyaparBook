<?php
// app/Http/Controllers/Api/V1/RawMaterialController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Policies\StockPolicy;
use App\Services\PlanGuard;
use App\Stock\MaterialUnit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RawMaterialController extends Controller
{
    // Stock & production is owner/admin only, reads included — the whole module
    // gates on StockPolicy::manage() (PRD §7), the deliberate difference from the
    // catalog and khata slices.

    public function store(Request $request)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', Rule::in(MaterialUnit::keys())],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Idempotent create by (business_id, uuid): a retried mutation with the
        // same uuid replays the existing row (200) instead of duplicating (201).
        // The web app has no outbox, so it lets the server mint the uuid.
        $uuid = $data['uuid'] ?? (string) Str::uuid();

        $existing = RawMaterial::where('uuid', $uuid)->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        // business_id is stamped by BelongsToTenant, never from the payload.
        $material = RawMaterial::create([
            'uuid' => $uuid,
            'name' => $data['name'],
            'unit' => $data['unit'],
            'reorder_level' => $data['reorder_level'] ?? null,
        ]);

        return response()->json($material, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        // findOrFail under RLS: another tenant's material is invisible → 404,
        // never a 403 that would leak its existence.
        $material = RawMaterial::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'unit' => ['sometimes', 'required', Rule::in(MaterialUnit::keys())],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ]);

        $material->update($data);

        return response()->json($material->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        // Archive, never delete: the stock ledger references this material.
        // archived_at is not fillable, so it is assigned directly.
        $material = RawMaterial::findOrFail($id);
        $material->archived_at = Carbon::now();
        $material->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $material = RawMaterial::findOrFail($id);
        $material->archived_at = null;
        $material->save();

        return response()->json($material->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage stock and production.'],
            403
        );
    }
}
