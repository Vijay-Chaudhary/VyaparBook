<?php
// app/Http/Controllers/Api/V1/PackSizeController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Policies\CatalogPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackSizeController extends Controller
{
    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            // No tenant clause on the unique rule, and none is needed: this query
            // runs inside SetTenantContext's transaction with app.current_tenant
            // set, so RLS has already narrowed pack_sizes to this business. It
            // looks like a missing scope; it is not.
            'label' => ['required', 'string', 'max:40', Rule::unique('pack_sizes', 'label')],
            'weight_kg' => ['required', 'numeric', 'gt:0'],
            'in_dropdown' => ['nullable', 'boolean'],
        ], [
            // An archived label still occupies the unique index. Restoring is the
            // right move, so say so rather than reporting a bare "already taken".
            'label.unique' => 'That pack size already exists. If it is archived, restore it instead.',
        ]);

        $packSize = PackSize::create($data);

        // fresh(): in_dropdown defaults to true at the DB level, and a create that
        // omitted it leaves the in-memory instance without the applied default.
        // Reload so the response carries the row's real persisted state.
        return response()->json($packSize->fresh(), 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);

        $data = $request->validate([
            'label' => [
                'sometimes', 'required', 'string', 'max:40',
                Rule::unique('pack_sizes', 'label')->ignore($packSize->id),
            ],
            'weight_kg' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'in_dropdown' => ['nullable', 'boolean'],
        ]);

        $packSize->update($data);

        return response()->json($packSize->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);
        $packSize->archived_at = Carbon::now();
        $packSize->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);
        $packSize->archived_at = null;
        $packSize->save();

        return response()->json($packSize->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
