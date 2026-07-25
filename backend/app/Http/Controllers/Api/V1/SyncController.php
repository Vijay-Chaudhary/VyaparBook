<?php
// app/Http/Controllers/Api/V1/SyncController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Customer;
use App\Models\MaterialConsumption;
use App\Models\Payment;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Policies\KhataPolicy;
use App\Policies\StockPolicy;
use App\Services\LedgerWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SyncController extends Controller
{
    /**
     * Delta pull: everything in this tenant that changed since the client's
     * cursor. Rows carry a globally monotonic sync_seq (HasSyncSequence), so
     * "changed since" is simply sync_seq > since, ordered by sync_seq. RLS and
     * BelongsToTenant guarantee only the caller's tenant appears even if a query
     * were imperfect. Archived rows are included — an archive bumps sync_seq, so
     * the client learns to hide the row.
     *
     * The response cursor is the max sync_seq returned, or `since` when nothing
     * changed, so a repeated pull with no writes is a stable empty delta.
     */
    public function pull(Request $request)
    {
        $request->validate(['since' => ['nullable', 'integer', 'min:0']]);
        $since = (int) $request->query('since', 0);

        $delta = fn (string $model) => $model::where('sync_seq', '>', $since)
            ->orderBy('sync_seq')
            ->get();

        $customers = $delta(Customer::class);
        $sales = $delta(Sale::class);
        $saleLines = $delta(SaleLine::class);
        $payments = $delta(Payment::class);

        // Stock & production is owner/admin only (PRD §7), reads included — so a
        // salesman's/accountant's pull must not stream those rows to their device.
        // Defense in depth over RLS's tenant scope: the app layer withholds them
        // by role. Non-managers get empty arrays; the response shape stays stable.
        $canSeeStock = (new StockPolicy())->manage();
        $stockDelta = fn (string $model) => $canSeeStock ? $delta($model) : collect();

        // Beats are pure server data the phone only reads. A salesman gets only
        // the beats assigned to them — another salesman's route is not their
        // business, and withholding it at the app layer is defense in depth over
        // RLS's tenant scope, exactly as stock is withheld above.
        $isManager = (new StockPolicy())->manage();
        $beats = $isManager
            ? $delta(Beat::class)
            : Beat::where('sync_seq', '>', $since)
                ->where('assigned_user_id', (int) app('tenant.user_id'))
                ->orderBy('sync_seq')
                ->get();

        // Membership rows follow their beat: a row whose beat was withheld would
        // point at nothing on the device.
        $beatIds = $beats->pluck('id');
        $beatCustomers = BeatCustomer::where('sync_seq', '>', $since)
            ->whereIn('beat_id', $beatIds)
            ->orderBy('sync_seq')
            ->get();

        $rawMaterials = $stockDelta(RawMaterial::class);
        $stockMovements = $stockDelta(StockMovement::class);
        $productionBatches = $stockDelta(ProductionBatch::class);
        $materialConsumptions = $stockDelta(MaterialConsumption::class);

        $maxSeqs = collect([
            $customers, $sales, $saleLines, $payments, $beats, $beatCustomers,
            $rawMaterials, $stockMovements, $productionBatches, $materialConsumptions,
        ])
            ->map(fn ($rows) => $rows->max('sync_seq'))
            ->filter(fn ($v) => $v !== null);

        return response()->json([
            'cursor' => $maxSeqs->isEmpty() ? $since : $maxSeqs->max(),
            'customers' => $customers,
            'sales' => $sales,
            'sale_lines' => $saleLines,
            'payments' => $payments,
            'raw_materials' => $rawMaterials,
            'stock_movements' => $stockMovements,
            'production_batches' => $productionBatches,
            'material_consumptions' => $materialConsumptions,
            'beats' => $beats,
            'beat_customers' => $beatCustomers,
        ]);
    }

    /**
     * Drain the client's offline outbox. Each mutation is applied idempotently by
     * (business_id, uuid) through the same LedgerWriter the REST endpoints use, so
     * an online and an offline create are the same write.
     *
     * Two guarantees per mutation:
     *  - tenant_id must equal the session tenant. Belt-and-suspenders with RLS's
     *    WITH CHECK: the app layer rejects a foreign tenant_id before the DB would.
     *  - the caller's role must permit the mutation type (PRD §7), same as REST.
     *
     * One bad mutation is reported, never fatal to the batch: each apply runs in
     * its own savepoint so a failure rolls back only that item and the loop
     * continues (a raw failure would otherwise abort the request transaction).
     */
    public function push(Request $request, LedgerWriter $writer)
    {
        $request->validate([
            'mutations' => ['present', 'array'],
            'mutations.*.type' => ['required', Rule::in(['customer', 'sale', 'payment'])],
            'mutations.*.tenant_id' => ['required', 'uuid'],
            'mutations.*.uuid' => ['required', 'uuid'],
            'mutations.*.payload' => ['required', 'array'],
        ]);

        $results = [];

        foreach ($request->input('mutations') as $mutation) {
            $uuid = $mutation['uuid'];

            if ($mutation['tenant_id'] !== app('tenant.id')) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'tenant_mismatch'];
                continue;
            }

            if (! $this->roleAllows($mutation['type'])) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'forbidden'];
                continue;
            }

            // The envelope uuid is the idempotency key; fold it into the payload
            // the writer validates and stores.
            $payload = array_merge($mutation['payload'], ['uuid' => $uuid]);

            $validator = Validator::make($payload, $this->rulesFor($mutation['type']));
            if ($validator->fails()) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'invalid'];
                continue;
            }

            try {
                [$model, $created] = DB::transaction(fn () => $this->apply($writer, $mutation['type'], $validator->validated()));
                $results[] = [
                    'uuid' => $uuid,
                    'status' => $created ? 'applied' : 'duplicate',
                    'id' => $model->id,
                ];
            } catch (ModelNotFoundException) {
                // A referenced customer/pack the caller cannot see (RLS) or that
                // does not exist. Report, do not fail the batch.
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'not_found'];
            } catch (ValidationException) {
                // A domain rule the writer enforces (e.g. a line below its cost
                // floor). Same shape as the other rejection branches: report,
                // do not fail the batch, and let the savepoint's rollback stand.
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'invalid'];
            }
        }

        return response()->json(['results' => $results]);
    }

    private function apply(LedgerWriter $writer, string $type, array $data): array
    {
        return match ($type) {
            'customer' => $writer->createCustomer($data),
            'sale' => $writer->createSale($data),
            'payment' => $writer->recordPayment($data),
        };
    }

    private function rulesFor(string $type): array
    {
        return match ($type) {
            'customer' => LedgerWriter::rulesForCustomer(),
            'sale' => LedgerWriter::rulesForSale(),
            'payment' => LedgerWriter::rulesForPayment(),
        };
    }

    private function roleAllows(string $type): bool
    {
        $policy = new KhataPolicy();

        return match ($type) {
            'customer', 'sale' => $policy->recordSale(),
            'payment' => $policy->recordPayment(),
        };
    }
}
