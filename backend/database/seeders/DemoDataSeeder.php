<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\MaterialConsumption;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductPack;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dev-only demo data spanning every module: tenancy, catalog, khata (customers,
 * sales, payments), stock, production, billing, DPDP consent, and the platform
 * superadmin.
 *
 * Every write goes through the privileged `pgsql_migrate` connection, and for a
 * good reason: a seeder runs outside a request, so no SetTenantContext
 * transaction has set `app.current_tenant`. On the restricted app connection the
 * RLS `WITH CHECK` predicate (`business_id = current_setting('app.current_tenant')`)
 * would reject every tenant-owned insert. `pgsql_migrate` is the schema-owning
 * superuser role, which bypasses RLS — the same mechanism the test suite uses for
 * setup rows. The app-layer BelongsToTenant global scope is a no-op here because
 * `app('tenant.id')` resolves to null (bound in AppServiceProvider), so explicit
 * `business_id` values pass through untouched.
 *
 * `created_by`, `total` and `line_total` are stamped by the writer services in
 * production, never mass-assigned; here they are set by explicit assignment via
 * {@see self::persist()}, mirroring what each factory's afterMaking() does.
 *
 * Idempotent: parents use updateOrCreate on natural keys; a business whose
 * customers already exist is treated as fully seeded and skipped, so re-running
 * never duplicates the transactional rows.
 */
class DemoDataSeeder extends Seeder
{
    private const CONNECTION = 'pgsql_migrate';

    private const POLICY_VERSION = '2026-01-01';

    public function run(): void
    {
        $this->platformAdmin();

        $this->seedTenant([
            'name' => 'Demo Namkeen Bhandar',
            'city' => 'Indore',
            'language' => 'en',
            'plan' => 'pro',
            'template' => 'namkeen',
            'subscription' => ['plan' => 'pro', 'status' => 'active'],
            'payment' => ['plan' => 'pro', 'amount' => '499.00', 'gst' => '89.82', 'status' => 'verified'],
        ]);

        $this->seedTenant([
            'name' => 'Demo Sweets House',
            'city' => 'Ujjain',
            'language' => 'hi',
            'plan' => 'trial',
            'template' => 'sweets',
            'subscription' => ['plan' => 'free', 'status' => 'trialing'],
            'payment' => ['plan' => 'pro', 'amount' => '499.00', 'gst' => '89.82', 'status' => 'pending'],
        ]);

        $this->command->info('Demo data seeded. Owners: owner@demo-namkeen.test / owner@demo-sweets.test (password123). Superadmin: admin@vyaparbook.test.');
    }

    private function platformAdmin(): void
    {
        $admin = $this->user('admin@vyaparbook.test', 'Platform Admin', '9800000000');
        $admin->is_platform_admin = true;
        $admin->save();
    }

    /** @param array<string, mixed> $spec */
    private function seedTenant(array $spec): void
    {
        $business = Business::on(self::CONNECTION)->updateOrCreate(
            ['name' => $spec['name']],
            ['city' => $spec['city'], 'default_language' => $spec['language'], 'plan' => $spec['plan']],
        );

        $slug = Str::slug($spec['name']);
        $owner = $this->staff($business, "owner@{$slug}.test", 'Demo Owner', 'owner');
        $this->staff($business, "admin@{$slug}.test", 'Demo Admin', 'admin');
        $this->staff($business, "salesman@{$slug}.test", 'Demo Salesman', 'salesman');
        $this->staff($business, "accountant@{$slug}.test", 'Demo Accountant', 'accountant');

        $this->consent($owner);
        $this->subscription($business, $spec['subscription']);
        $this->subscriptionPayment($business, $spec['payment']);

        if (Customer::on(self::CONNECTION)->where('business_id', $business->id)->exists()) {
            $this->command->info("  {$spec['name']}: already populated — skipping transactional data.");

            return;
        }

        $packs = $this->applyCatalogTemplate($spec['template'], $business->id);
        $customers = $this->customers($business);
        $this->khataLoop($business, $owner->id, $customers, $packs);
        $materials = $this->stock($business, $owner->id);
        $this->production($business, $owner->id, $packs, $materials);

        $this->command->info("  {$spec['name']}: seeded catalog, ".count($customers).' customers, sales, payments, stock and production.');
    }

    private function staff(Business $business, string $email, string $name, string $role): User
    {
        $user = $this->user($email, $name, $this->phone());

        Membership::on(self::CONNECTION)->updateOrCreate(
            ['user_id' => $user->id, 'business_id' => $business->id],
            ['role' => $role],
        );

        return $user;
    }

    private function user(string $email, string $name, string $phone): User
    {
        return User::on(self::CONNECTION)->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'phone' => $phone, 'password' => Hash::make('password123')],
        );
    }

    private function consent(User $owner): void
    {
        if (Consent::on(self::CONNECTION)->where('user_id', $owner->id)->exists()) {
            return;
        }

        Consent::on(self::CONNECTION)->create([
            'user_id' => $owner->id,
            'action' => Consent::GRANTED,
            'policy_version' => self::POLICY_VERSION,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'DemoDataSeeder',
        ]);
    }

    /** @param array{plan: string, status: string} $spec */
    private function subscription(Business $business, array $spec): void
    {
        $active = $spec['status'] === 'active';

        Subscription::on(self::CONNECTION)->updateOrCreate(
            ['business_id' => $business->id],
            [
                'plan' => $spec['plan'],
                'status' => $spec['status'],
                'trial_ends_at' => $active ? null : Carbon::now()->addDays(14),
                'current_period_end' => $active ? Carbon::now()->addDays(30) : null,
            ],
        );
    }

    /** @param array{plan: string, amount: string, gst: string, status: string} $spec */
    private function subscriptionPayment(Business $business, array $spec): void
    {
        if (SubscriptionPayment::on(self::CONNECTION)->where('business_id', $business->id)->exists()) {
            return;
        }

        SubscriptionPayment::on(self::CONNECTION)->create([
            'business_id' => $business->id,
            'uuid' => (string) Str::uuid(),
            'plan' => $spec['plan'],
            'amount' => $spec['amount'],
            'gst_amount' => $spec['gst'],
            'mode' => 'upi',
            'reference' => 'DEMO'.strtoupper(Str::random(8)),
            'period_months' => 1,
            'status' => $spec['status'],
        ]);
    }

    /**
     * Insert a catalog template's products, pack sizes and product packs on the
     * privileged connection. Mirrors CatalogTemplateService::apply(), which
     * cannot run here because it writes on the RLS-restricted app connection.
     *
     * @return list<ProductPack> the created packs, used to build sale lines
     */
    private function applyCatalogTemplate(string $slug, string $businessId): array
    {
        $template = require database_path("catalog_templates/{$slug}.php");
        $catalog = app(CatalogService::class);

        $products = [];
        foreach ($template['products'] as $key => $attrs) {
            $products[$key] = Product::on(self::CONNECTION)->create($attrs + ['business_id' => $businessId]);
        }

        $packSizes = [];
        foreach ($template['pack_sizes'] as $key => $attrs) {
            $packSizes[$key] = PackSize::on(self::CONNECTION)->create($attrs + ['business_id' => $businessId]);
        }

        $packs = [];
        foreach ($template['product_packs'] as $row) {
            $product = $products[$row['product']];
            $packSize = $packSizes[$row['pack']];

            $packs[] = ProductPack::on(self::CONNECTION)->create([
                'business_id' => $businessId,
                'product_id' => $product->id,
                'pack_size_id' => $packSize->id,
                'default_sell_price' => $row['default_sell_price'],
                'default_cost_price' => $row['default_cost_price']
                    ?? $catalog->suggestedCostPrice($product, $packSize),
            ]);
        }

        return $packs;
    }

    /** @return list<Customer> */
    private function customers(Business $business): array
    {
        $rows = [
            ['Ramesh Traders', 'Rampur', '1500.00'],
            ['Sunita General Store', 'Bagru', '0.00'],
            ['Mahaveer Kirana', 'Chomu', '4200.50'],
            ['Gupta Provision', 'Sanganer', '0.00'],
            ['Anil Snacks Corner', null, '875.00'],
        ];

        $customers = [];
        foreach ($rows as [$name, $village, $opening]) {
            $customers[] = Customer::on(self::CONNECTION)->create([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'village' => $village,
                'phone' => $this->phone(),
                'opening_balance' => $opening,
            ]);
        }

        return $customers;
    }

    /**
     * The core khata loop: each of the first few customers gets a couple of
     * multi-line sales and a part-payment, so ledgers show real running balances.
     *
     * @param  list<Customer>     $customers
     * @param  list<ProductPack>  $packs
     */
    private function khataLoop(Business $business, int $ownerId, array $customers, array $packs): void
    {
        foreach (array_slice($customers, 0, 4) as $index => $customer) {
            foreach (range(1, 2) as $n) {
                $saleDate = Carbon::now()->subDays(($index * 5) + $n)->toDateString();

                $sale = $this->persist(new Sale([
                    'business_id' => $business->id,
                    'uuid' => (string) Str::uuid(),
                    'customer_id' => $customer->id,
                    'sale_date' => $saleDate,
                ]), ['created_by' => $ownerId, 'total' => '0.00']);

                $total = '0.00';
                foreach ($this->pickPacks($packs, $n + 1) as $offset => $pack) {
                    $qty = ($offset + 1) * 2;
                    $rate = (string) $pack->default_sell_price;
                    $lineTotal = bcmul($rate, (string) $qty, 2);
                    $total = bcadd($total, $lineTotal, 2);

                    $this->persist(new SaleLine([
                        'business_id' => $business->id,
                        'sale_id' => $sale->id,
                        'product_pack_id' => $pack->id,
                        'qty' => $qty,
                        'rate' => $rate,
                    ]), ['line_total' => $lineTotal]);
                }

                $sale->total = $total;
                $sale->save();
            }

            // A single part-payment against the running balance.
            $this->persist(new Payment([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'payment_date' => Carbon::now()->subDays($index)->toDateString(),
                'amount' => '500.00',
                'mode' => $index % 2 === 0 ? 'cash' : 'upi',
            ]), ['created_by' => $ownerId]);
        }
    }

    /** @return list<RawMaterial> */
    private function stock(Business $business, int $ownerId): array
    {
        $definitions = [
            ['Besan', 'kg', '25.000'],
            ['Refined Oil', 'litre', '15.000'],
            ['Salt', 'kg', '5.000'],
            ['Red Chilli', 'kg', '3.000'],
        ];

        $materials = [];
        foreach ($definitions as [$name, $unit, $reorder]) {
            $material = RawMaterial::on(self::CONNECTION)->create([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'unit' => $unit,
                'reorder_level' => $reorder,
            ]);

            // An opening purchase (in) and a small consumption (out).
            $this->persist(new StockMovement([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $material->id,
                'movement_date' => Carbon::now()->subDays(10)->toDateString(),
                'kind' => 'in',
                'qty' => '100.000',
                'note' => 'Opening purchase',
            ]), ['created_by' => $ownerId]);

            $this->persist(new StockMovement([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $material->id,
                'movement_date' => Carbon::now()->subDays(3)->toDateString(),
                'kind' => 'out',
                'qty' => '12.000',
                'note' => 'Kitchen issue',
            ]), ['created_by' => $ownerId]);

            $materials[] = $material;
        }

        return $materials;
    }

    /**
     * @param  list<ProductPack>   $packs
     * @param  list<RawMaterial>   $materials
     */
    private function production(Business $business, int $ownerId, array $packs, array $materials): void
    {
        if ($packs === [] || $materials === []) {
            return;
        }

        $productIds = array_values(array_unique(array_map(fn (ProductPack $p) => $p->product_id, $packs)));

        foreach (array_slice($productIds, 0, 2) as $i => $productId) {
            $batch = $this->persist(new ProductionBatch([
                'business_id' => $business->id,
                'uuid' => (string) Str::uuid(),
                'product_id' => $productId,
                'batch_date' => Carbon::now()->subDays(4 - $i)->toDateString(),
                'output_kg' => '50.000',
            ]), ['created_by' => $ownerId]);

            foreach (array_slice($materials, 0, 2) as $material) {
                MaterialConsumption::on(self::CONNECTION)->create([
                    'business_id' => $business->id,
                    'production_batch_id' => $batch->id,
                    'raw_material_id' => $material->id,
                    'qty' => '10.000',
                ]);
            }
        }
    }

    /**
     * Persist a tenant-owned model with non-fillable columns (created_by, total,
     * line_total) set explicitly. new + save rather than create() because those
     * columns are guarded against mass assignment by design.
     *
     * @param  array<string, mixed>  $guarded
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function persist($model, array $guarded)
    {
        $model->setConnection(self::CONNECTION);

        foreach ($guarded as $column => $value) {
            $model->{$column} = $value;
        }

        $model->save();

        return $model;
    }

    /**
     * Deterministically pick a few packs without repetition.
     *
     * @param  list<ProductPack>  $packs
     * @return list<ProductPack>
     */
    private function pickPacks(array $packs, int $count): array
    {
        if ($packs === []) {
            return [];
        }

        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $packs[($i * 3) % count($packs)];
        }

        return $picked;
    }

    private function phone(): string
    {
        static $counter = 0;

        return '98'.str_pad((string) (10000000 + $counter++), 8, '0', STR_PAD_LEFT);
    }
}
