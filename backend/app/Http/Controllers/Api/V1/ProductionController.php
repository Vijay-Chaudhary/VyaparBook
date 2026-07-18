<?php
// app/Http/Controllers/Api/V1/ProductionController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Policies\StockPolicy;
use App\Services\ProductionWriter;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function store(Request $request, ProductionWriter $writer)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
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
