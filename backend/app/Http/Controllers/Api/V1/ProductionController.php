<?php
// app/Http/Controllers/Api/V1/ProductionController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ledger\ReversalNotAllowed;
use App\Models\ProductionBatch;
use App\Policies\StockPolicy;
use App\Services\PlanGuard;
use App\Services\ProductionWriter;
use App\Stock\StockReverser;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(private readonly StockReverser $reverser) {}

    /**
     * Correct a batch: negate its output AND put back the materials it drew.
     *
     * Both, because the batch did both. A reversal that only negated the output
     * would leave raw materials consumed by a batch that no longer counts as
     * having happened.
     */
    public function reverse(string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $original = ProductionBatch::with(['consumptions', 'movements'])->findOrFail($id);

        try {
            $reversal = $this->reverser->reverseBatch($original);
        } catch (ReversalNotAllowed $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->reason === ReversalNotAllowed::ALREADY_REVERSED ? 409 : 422
            );
        }

        return response()->json($reversal, 201);
    }

    public function store(Request $request, ProductionWriter $writer)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $data = $request->validate(ProductionWriter::rulesForBatch());

        // Idempotent create through the shared writer: it records the consumptions
        // and draws stock down in one transaction.
        [$batch, $created] = $writer->createBatch($data);

        return response()->json($batch, $created ? 201 : 200);
    }

    /**
     * The production log: batches newest first with product, date and output.
     * Owner/admin only (PRD §7).
     */
    public function index()
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $batches = ProductionBatch::query()
            ->with('product')
            ->orderByDesc('batch_date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'batches' => $batches->map(fn (ProductionBatch $b) => [
                'id' => $b->id,
                'product_id' => $b->product_id,
                'product_name' => $b->product?->name_hi,
                'batch_date' => $b->batch_date,
                'output_kg' => $b->output_kg,
                'version' => $b->version,
            ])->values(),
        ]);
    }

    /**
     * One batch with its material consumptions. findOrFail under RLS → a
     * cross-tenant id 404s.
     */
    public function show(string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $batch = ProductionBatch::with(['product', 'consumptions.rawMaterial'])->findOrFail($id);

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'product_id' => $batch->product_id,
                'product_name' => $batch->product?->name_hi,
                'batch_date' => $batch->batch_date,
                'output_kg' => $batch->output_kg,
            ],
            'consumptions' => $batch->consumptions->map(fn ($c) => [
                'id' => $c->id,
                'raw_material_id' => $c->raw_material_id,
                'raw_material_name' => $c->rawMaterial?->name,
                'qty' => $c->qty,
            ])->values(),
        ]);
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage stock and production.'],
            403
        );
    }
}
