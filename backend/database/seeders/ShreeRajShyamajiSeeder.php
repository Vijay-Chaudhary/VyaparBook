<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use App\Models\MaterialConsumption;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductPack;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogService;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The real records of Shree Raj Shyama Ji Namkeen, Hata — April to June 2026.
 *
 * Replaces the invented demo tenants. Business data lives in
 * database/seed_data/shreerajshyamaji/*.php, one file per master; this class
 * holds the insert logic and nothing else, so a re-export from the owner's
 * spreadsheet replaces a data file rather than editing code.
 *
 * A seeder runs outside a request, so nothing has bound a tenant and the
 * fail-closed BelongsToTenant scope would refuse every query. run() therefore
 * executes inside Tenancy::withoutTenant() — seeders are one of the sanctioned
 * cross-tenant paths listed on that class — and the explicit business_id values
 * below stand on their own.
 *
 * Idempotent: masters use updateOrCreate on natural keys, and a business whose
 * customers already exist is treated as fully seeded, so re-running never
 * duplicates the transactional rows.
 */
class ShreeRajShyamajiSeeder extends Seeder
{
    private const BUSINESS = 'Shree Raj Shyama Ji Namkeen';

    private string $businessId;

    private int $ownerId;

    /** @var array<string, string> "name|village" => customer id */
    private array $customers = [];

    /** @var array<string, string> supplier name => id */
    private array $suppliers = [];

    /** @var array<string, string> material name => id */
    private array $materials = [];

    /** @var array<string, string> template key => product id */
    private array $products = [];

    /** @var array<string, string> "productKey|packKey" => product_pack id */
    private array $packs = [];

    public function run(): void
    {
        // Wrapped here as well as in DatabaseSeeder, because this seeder is
        // also called on its own (tests, `db:seed --class`).
        Tenancy::withoutTenant(function () {
            $business = Business::updateOrCreate(
                ['name' => self::BUSINESS],
                ['city' => 'Hata', 'default_language' => 'hi', 'plan' => 'trial'],
            );

            $this->businessId = $business->id;
            $this->ownerId = $this->owner($business)->id;

            $this->catalog();
            $this->masters();
            $this->purchases();
            $this->sales();
            $this->payments();
            $this->production();
        });

        $this->command->info(self::BUSINESS.': seeded catalog and masters.');
    }

    private function owner(Business $business): User
    {
        $user = User::updateOrCreate(
            ['email' => 'owner@vyaparbook.test'],
            ['name' => 'Shree Raj Shyama Ji', 'phone' => '9876500001', 'password' => Hash::make('password123')],
        );

        Membership::updateOrCreate(
            ['user_id' => $user->id, 'business_id' => $business->id],
            ['role' => 'owner'],
        );

        return $user;
    }

    /** @return array<string, mixed> */
    private function data(string $file): array
    {
        return require database_path("seed_data/shreerajshyamaji/{$file}.php");
    }

    private function catalog(): void
    {
        $template = $this->data('catalog');
        $catalog = app(CatalogService::class);

        foreach ($template['products'] as $key => $attrs) {
            $this->products[$key] = Product::updateOrCreate(
                ['business_id' => $this->businessId, 'name_en' => $attrs['name_en']],
                $attrs,
            )->id;
        }

        $sizes = [];
        foreach ($template['pack_sizes'] as $key => $attrs) {
            $sizes[$key] = PackSize::updateOrCreate(
                ['business_id' => $this->businessId, 'label' => $attrs['label']],
                $attrs,
            );
        }

        foreach ($template['product_packs'] as $row) {
            $product = Product::find($this->products[$row['product']]);
            $size = $sizes[$row['pack']];

            $pack = ProductPack::updateOrCreate(
                [
                    'business_id' => $this->businessId,
                    'product_id' => $product->id,
                    'pack_size_id' => $size->id,
                ],
                [
                    'default_sell_price' => $row['default_sell_price'],
                    'default_cost_price' => $catalog->suggestedCostPrice($product, $size),
                ],
            );

            $this->packs[$row['product'].'|'.$row['pack']] = $pack->id;
        }
    }

    private function masters(): void
    {
        foreach ($this->data('customers') as [$name, $village]) {
            $row = Customer::updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name, 'village' => $village],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            );

            // Keyed on name AND village: two names repeat across villages.
            $this->customers[$name.'|'.$village] = $row->id;
        }

        foreach ($this->data('suppliers') as $name) {
            $this->suppliers[$name] = Supplier::updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            )->id;
        }

        foreach ($this->data('materials') as [$name, $unit, $reorder]) {
            $this->materials[$name] = RawMaterial::updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name],
                ['uuid' => (string) Str::uuid(), 'unit' => $unit, 'reorder_level' => $reorder],
            )->id;
        }
    }

    /**
     * Purchases, each with the positive `in` movement that actually raises
     * stock — on-hand is Σ stock_movements.qty, so a Purchase row on its own
     * would leave every material reading zero. Mirrors PurchaseWriter.
     */
    private function purchases(): void
    {
        if (Purchase::where('business_id', $this->businessId)->exists()) {
            return;
        }

        foreach ($this->data('purchases') as [$date, $material, $qty, $unitCost, $supplier]) {
            // Built with `new` rather than create(): created_by is NOT NULL and
            // guarded, so the row has to be stamped before its first insert.
            $purchase = new Purchase([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'supplier_id' => $this->suppliers[$supplier],
                'raw_material_id' => $this->materials[$material],
                'purchase_date' => $date,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total' => bcmul($qty, $unitCost, 2),
            ]);
            $purchase->created_by = $this->ownerId;
            $purchase->save();

            $movement = new StockMovement([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $this->materials[$material],
                'movement_date' => $date,
                'kind' => 'in',
                'qty' => $qty,
                'purchase_id' => $purchase->id,
            ]);
            $movement->created_by = $this->ownerId;
            $movement->save();
        }
    }

    /**
     * Sale lines grouped into sales by (customer, date), as the owner's book
     * records them: one visit, several products.
     *
     * The rate is derived per line from what was charged, never taken from the
     * pack default — the same pack sells at different prices to different
     * customers, and flattening that would misstate every margin.
     */
    private function sales(): void
    {
        if (Sale::where('business_id', $this->businessId)->exists()) {
            return;
        }

        $grouped = [];
        foreach ($this->data('sales') as $row) {
            [$date, $name, $village, $product, $pack, $qty, $amount] = $row;
            $grouped[$date.'|'.$name.'|'.$village][] = [$product, $pack, $qty, $amount];
        }

        foreach ($grouped as $key => $lines) {
            [$date, $name, $village] = explode('|', $key);

            $sale = new Sale([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'customer_id' => $this->customers[$name.'|'.$village],
                'sale_date' => $date,
            ]);
            $sale->created_by = $this->ownerId;
            $sale->total = '0.00';
            $sale->save();

            $total = '0.00';
            foreach ($lines as [$product, $pack, $qty, $amount]) {
                $lineTotal = bcadd($amount, '0', 2);
                // Rate is per pack and always positive; the sign lives on qty,
                // so a return reads as "9 packs back at the price paid".
                $rate = bcdiv($lineTotal, (string) $qty, 2);
                $total = bcadd($total, $lineTotal, 2);

                $line = new SaleLine([
                    'business_id' => $this->businessId,
                    'sale_id' => $sale->id,
                    'product_pack_id' => $this->packs[$product.'|'.$pack],
                    'qty' => $qty,
                    'rate' => $rate,
                ]);
                $line->line_total = $lineTotal;
                $line->save();
            }

            $sale->total = $total;
            $sale->save();
        }
    }

    /** Mode is 'cash' throughout: the owner's ledger records amount and date, not tender. */
    private function payments(): void
    {
        if (Payment::where('business_id', $this->businessId)->exists()) {
            return;
        }

        foreach ($this->data('payments') as [$date, $name, $village, $amount]) {
            $payment = new Payment([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'customer_id' => $this->customers[$name.'|'.$village],
                'payment_date' => $date,
                'amount' => bcadd($amount, '0', 2),
                'mode' => 'cash',
            ]);
            $payment->created_by = $this->ownerId;
            $payment->save();
        }
    }

    /**
     * Batches and what they consumed, each consumption paired with the negative
     * `out` movement that actually lowers stock — mirrors ProductionWriter.
     *
     * RECONSTRUCTED, not transcribed. See the spec: the owner's log covers
     * 770 kg against 1,654 kg sold, with no Senvda batch, so costing it
     * verbatim reports a loss on every sale.
     */
    private function production(): void
    {
        if (ProductionBatch::where('business_id', $this->businessId)->exists()) {
            return;
        }

        foreach ($this->data('production') as [$date, $product, $outputKg, $consumptions]) {
            $batch = new ProductionBatch([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'product_id' => $this->products[$product],
                'batch_date' => $date,
                'output_kg' => $outputKg,
            ]);
            $batch->created_by = $this->ownerId;
            $batch->save();

            foreach ($consumptions as [$material, $qty]) {
                MaterialConsumption::create([
                    'business_id' => $this->businessId,
                    'production_batch_id' => $batch->id,
                    'raw_material_id' => $this->materials[$material],
                    'qty' => $qty,   // positive amount consumed
                ]);

                $movement = new StockMovement([
                    'business_id' => $this->businessId,
                    'uuid' => (string) Str::uuid(),
                    'raw_material_id' => $this->materials[$material],
                    'movement_date' => $date,
                    'kind' => 'out',
                    // Signed negative, or it would RAISE stock.
                    'qty' => bcmul($qty, '-1', 3),
                    'production_batch_id' => $batch->id,
                ]);
                $movement->created_by = $this->ownerId;
                $movement->save();
            }
        }
    }
}
