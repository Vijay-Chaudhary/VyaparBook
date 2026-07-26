<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogService;
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
 * Every write goes through the privileged `pgsql_migrate` connection. A seeder
 * runs outside a request, so no SetTenantContext transaction has set
 * `app.current_tenant`, and the RLS WITH CHECK predicate would reject every
 * tenant-owned insert on the app connection. The app-layer BelongsToTenant
 * scope is a no-op here because `app('tenant.id')` resolves to null, so the
 * explicit business_id values below pass through untouched.
 *
 * Idempotent: masters use updateOrCreate on natural keys, and a business whose
 * customers already exist is treated as fully seeded, so re-running never
 * duplicates the transactional rows.
 */
class ShreeRajShyamajiSeeder extends Seeder
{
    private const CONNECTION = 'pgsql_migrate';

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
        $business = Business::on(self::CONNECTION)->updateOrCreate(
            ['name' => self::BUSINESS],
            ['city' => 'Hata', 'default_language' => 'hi', 'plan' => 'trial'],
        );

        $this->businessId = $business->id;
        $this->ownerId = $this->owner($business)->id;

        $this->catalog();
        $this->masters();
        $this->purchases();

        $this->command->info(self::BUSINESS.': seeded catalog and masters.');
    }

    private function owner(Business $business): User
    {
        $user = User::on(self::CONNECTION)->updateOrCreate(
            ['email' => 'owner@vyaparbook.test'],
            ['name' => 'Shree Raj Shyama Ji', 'phone' => '9876500001', 'password' => Hash::make('password123')],
        );

        Membership::on(self::CONNECTION)->updateOrCreate(
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
            $this->products[$key] = Product::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name_en' => $attrs['name_en']],
                $attrs,
            )->id;
        }

        $sizes = [];
        foreach ($template['pack_sizes'] as $key => $attrs) {
            $sizes[$key] = PackSize::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'label' => $attrs['label']],
                $attrs,
            );
        }

        foreach ($template['product_packs'] as $row) {
            $product = Product::on(self::CONNECTION)->find($this->products[$row['product']]);
            $size = $sizes[$row['pack']];

            $pack = ProductPack::on(self::CONNECTION)->updateOrCreate(
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
            $row = Customer::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name, 'village' => $village],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            );

            // Keyed on name AND village: two names repeat across villages.
            $this->customers[$name.'|'.$village] = $row->id;
        }

        foreach ($this->data('suppliers') as $name) {
            $this->suppliers[$name] = Supplier::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            )->id;
        }

        foreach ($this->data('materials') as [$name, $unit, $reorder]) {
            $this->materials[$name] = RawMaterial::on(self::CONNECTION)->updateOrCreate(
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
        if (Purchase::on(self::CONNECTION)->where('business_id', $this->businessId)->exists()) {
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
            $purchase->setConnection(self::CONNECTION);
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
            $movement->setConnection(self::CONNECTION);
            $movement->created_by = $this->ownerId;
            $movement->save();
        }
    }
}
