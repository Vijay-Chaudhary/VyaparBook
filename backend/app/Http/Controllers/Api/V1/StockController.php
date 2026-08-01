<?php
// app/Http/Controllers/Api/V1/StockController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Policies\StockPolicy;
use App\Services\PlanGuard;
use App\Services\StockService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    /**
     * The stock-on-hand screen: every non-archived material with its on-hand
     * (Σ movements), reorder level and below-reorder flag. Owner/admin only —
     * unlike GET /khata and GET /catalog, this read is gated (PRD §7).
     * ?include_archived=1 shows archived materials too.
     */
    public function index(Request $request, StockService $stock)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $includeArchived = $request->boolean('include_archived');

        $materials = RawMaterial::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'materials' => $materials->map(fn (RawMaterial $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'unit' => $m->unit,
                'reorder_level' => $m->reorder_level,
                'on_hand' => $stock->onHandFor($m),
                'below_reorder' => $stock->belowReorder($m),
                'archived_at' => $m->archived_at,
                'version' => $m->version,
            ])->values(),
        ]);
    }

    /**
     * One material's stock: the movement ledger with a running on-hand, plus the
     * final on-hand. findOrFail under the tenant scope → a cross-tenant id 404s.
     */
    public function show(string $id, StockService $stock)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $material = RawMaterial::findOrFail($id);
        $ledger = $stock->ledgerFor($material);

        return response()->json([
            'material' => [
                'id' => $material->id,
                'name' => $material->name,
                'unit' => $material->unit,
                'reorder_level' => $material->reorder_level,
            ],
            'on_hand' => $stock->onHandFor($material),
            'below_reorder' => $stock->belowReorder($material),
            'ledger' => $ledger->map(fn ($e) => [
                'id' => $e['movement']->id,
                'kind' => $e['movement']->kind,
                'date' => $e['movement']->movement_date,
                'qty' => $e['movement']->qty,
                'note' => $e['movement']->note,
                'production_batch_id' => $e['movement']->production_batch_id,
                'running_on_hand' => $e['running_on_hand'],
            ])->values(),
        ]);
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can view stock and production.'],
            403
        );
    }
}
