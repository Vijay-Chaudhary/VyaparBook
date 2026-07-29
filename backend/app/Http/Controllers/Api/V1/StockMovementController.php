<?php
// app/Http/Controllers/Api/V1/StockMovementController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ledger\ReversalNotAllowed;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Policies\StockPolicy;
use App\Services\PlanGuard;
use App\Stock\StockReverser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockReverser $reverser) {}

    /**
     * Correct a movement by writing its mirror image.
     *
     * Append-only: on-hand is Σ qty, so deleting a row would silently restate
     * it. The two stay visible and net to nothing.
     */
    public function reverse(string $id)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        // findOrFail under RLS: a cross-tenant movement is invisible → 404.
        $original = StockMovement::findOrFail($id);

        try {
            $reversal = $this->reverser->reverseMovement($original);
        } catch (ReversalNotAllowed $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->reason === ReversalNotAllowed::ALREADY_REVERSED ? 409 : 422
            );
        }

        return response()->json($reversal, 201);
    }

    public function store(Request $request)
    {
        if (! (new StockPolicy())->manage()) {
            return $this->denied();
        }

        if ($blocked = app(PlanGuard::class)->stockFeatureBlock()) {
            return $blocked;
        }

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'raw_material_id' => ['required', 'uuid'],
            'movement_date' => ['required', 'date'],
            'kind' => ['required', Rule::in(['in', 'out', 'adjust'])],
            // Magnitude for in/out (sign is derived); a signed non-zero delta for
            // adjust. Zero in any form is a no-op the ledger rejects, not records —
            // checked with bccomp so "0", "0.000" and "-0" are all caught.
            'qty' => ['required', 'numeric', function (string $attr, mixed $value, callable $fail) {
                if (bccomp((string) $value, '0', 3) === 0) {
                    $fail('The qty must not be zero.');
                }
            }],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // in/out take a positive magnitude — a negative one is ambiguous against
        // the kind, so reject it. adjust accepts either sign as the delta.
        if (in_array($data['kind'], ['in', 'out'], true) && bccomp((string) $data['qty'], '0', 3) <= 0) {
            return response()->json([
                'message' => 'The qty field must be greater than 0 for in/out movements.',
                'errors' => ['qty' => ['The qty must be greater than 0 for in/out movements.']],
            ], 422);
        }

        // Idempotent replay by (business_id, uuid): a retried record returns the
        // existing movement (200), never a double draw-down/top-up.
        $existing = StockMovement::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        // findOrFail under RLS: a cross-tenant material is invisible → 404.
        $material = RawMaterial::findOrFail($data['raw_material_id']);

        // Derive the signed stored qty from kind: in → +, out → −, adjust → as
        // given. Σ qty is then the on-hand total and an `out` can never raise it.
        $signed = $this->signedQty($data['kind'], (string) $data['qty']);

        // business_id via BelongsToTenant, created_by from the tenant context —
        // neither is taken from the payload.
        $movement = new StockMovement([
            'business_id' => app('tenant.id'),
            'uuid' => $data['uuid'],
            'raw_material_id' => $material->id,
            'movement_date' => $data['movement_date'],
            'kind' => $data['kind'],
            'qty' => $signed,
            'note' => $data['note'] ?? null,
        ]);
        $movement->created_by = app('tenant.user_id');
        $movement->save();

        return response()->json($movement, 201);
    }

    private function signedQty(string $kind, string $qty): string
    {
        return match ($kind) {
            'out' => bcmul($qty, '-1', 3),   // draws stock down
            'in' => bcadd($qty, '0', 3),     // raises stock (already positive)
            'adjust' => bcadd($qty, '0', 3), // signed delta as given
        };
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage stock and production.'],
            403
        );
    }
}
