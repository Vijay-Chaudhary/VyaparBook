<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Services\InvoiceIssuer;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function inGstTenant(string $businessId, int $userId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $userId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);
        app()->bind('tenant.user_id', fn () => $userId);

        return $fn();
    });
}

/** A registered shop with one product sold at a GST-inclusive price. */
function gstShop(string $defaultRate = '5.00', ?string $gstin = '09ABCDE1234F1Z5'): array
{
    $business = Business::factory()->create();
    $owner = User::factory()->create();

    DB::connection('pgsql_migrate')->table('businesses')->where('id', $business->id)->update([
        'gstin' => $gstin, 'default_gst_rate_percent' => $defaultRate, 'state_code' => '09',
    ]);

    return [$business->fresh(), $owner];
}

function gstSale(Business $b, User $u, string $lineTotal = '105.00', int $qty = 1, ?string $rate = null, ?string $hsn = '21069099'): Sale
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'name_hi' => 'Bhujia', 'name_en' => 'Bhujia',
    ]);
    // Assigned explicitly, not mass-filled: the GST columns are deliberately
    // absent from $fillable so they stay server-side only.
    $product->hsn_code = $hsn;
    $product->gst_rate_percent = $rate;
    $product->save();
    // firstOrCreate: pack_sizes is unique per (business, label), and several
    // tests invoice more than one sale for the same shop.
    $size = PackSize::on('pgsql_migrate')->firstOrCreate(
        ['business_id' => $b->id, 'label' => '1kg'],
        ['weight_kg' => '1.000'],
    );
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => $lineTotal,
    ]);
    $customer = Customer::on('pgsql_migrate')->firstOrCreate(
        ['business_id' => $b->id, 'name' => 'Ramesh'],
        ['uuid' => (string) Str::uuid(), 'village' => 'Rampur', 'opening_balance' => '0.00'],
    );

    $sale = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(),
    ]);
    $sale->setConnection('pgsql_migrate');
    $sale->total = bcmul($lineTotal, (string) $qty, 2);
    $sale->created_by = $u->id;
    $sale->save();

    $line = new SaleLine([
        'business_id' => $b->id, 'sale_id' => $sale->id,
        'product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => $lineTotal,
    ]);
    $line->setConnection('pgsql_migrate');
    $line->line_total = bcmul($lineTotal, (string) $qty, 2);
    $line->save();

    return $sale;
}

function issue(Business $b, User $u, Sale $sale, ?string $buyerGstin = null): Invoice
{
    return inGstTenant($b->id, $u->id, fn () => app(InvoiceIssuer::class)->issue($sale->id, $buyerGstin));
}

/**
 * The sole line of an invoice, read past the tenant scope — these assertions
 * run outside the pin, where BelongsToTenant would hide every row.
 */
function soleLine(Invoice $invoice): object
{
    return DB::connection('pgsql_migrate')->table('invoice_lines')
        ->where('invoice_id', $invoice->id)->sole();
}

beforeEach(fn () => Carbon::setTestNow('2026-07-25'));
afterEach(fn () => Carbon::setTestNow());

it('numbers the first invoice 0001 in the April-March financial year', function () {
    [$b, $u] = gstShop();

    $invoice = issue($b, $u, gstSale($b, $u));

    expect($invoice->financial_year)->toBe('2026-27');
    expect($invoice->seq)->toBe(1);
    expect($invoice->number)->toBe('2026-27/0001');
});

it('increments without gaps', function () {
    [$b, $u] = gstShop();

    $first = issue($b, $u, gstSale($b, $u));
    $second = issue($b, $u, gstSale($b, $u));
    $third = issue($b, $u, gstSale($b, $u));

    expect([$first->seq, $second->seq, $third->seq])->toBe([1, 2, 3]);
    expect($third->number)->toBe('2026-27/0003');
});

it('starts a fresh series in the next financial year', function () {
    [$b, $u] = gstShop();
    issue($b, $u, gstSale($b, $u));

    Carbon::setTestNow('2027-04-02');           // new FY
    $next = issue($b, $u, gstSale($b, $u));

    expect($next->financial_year)->toBe('2027-28');
    expect($next->seq)->toBe(1);
});

it('puts a March sale in the old year and an April sale in the new one', function () {
    [$b, $u] = gstShop();

    Carbon::setTestNow('2027-03-31');
    expect(issue($b, $u, gstSale($b, $u))->financial_year)->toBe('2026-27');

    Carbon::setTestNow('2027-04-01');
    expect(issue($b, $u, gstSale($b, $u))->financial_year)->toBe('2027-28');
});

it('totals to exactly the sale total, so the invoice cannot contradict the khata', function () {
    [$b, $u] = gstShop('5.00');
    $sale = gstSale($b, $u, '99.99', 3);          // deliberately awkward

    $invoice = issue($b, $u, $sale);

    expect((string) $invoice->grand_total)->toBe((string) $sale->fresh()->total);
    $sum = bcadd(bcadd((string) $invoice->taxable_total, (string) $invoice->cgst_total, 2), (string) $invoice->sgst_total, 2);
    expect($sum)->toBe((string) $invoice->grand_total);
});

it('snapshots the line so a later product change cannot alter a filed invoice', function () {
    [$b, $u] = gstShop('5.00');
    $sale = gstSale($b, $u, '105.00', 1, rate: '12.00', hsn: '21069099');

    $invoice = issue($b, $u, $sale);
    $line = soleLine($invoice);

    expect((string) $line->gst_rate_percent)->toBe('12.00');   // product's own rate, not the shop default
    expect($line->hsn_code)->toBe('21069099');

    // Change the product afterwards; the filed document must not move.
    DB::connection('pgsql_migrate')->table('products')->where('business_id', $b->id)
        ->update(['gst_rate_percent' => '28.00', 'hsn_code' => '99999999']);

    $reloaded = soleLine($invoice);
    expect((string) $reloaded->gst_rate_percent)->toBe('12.00');
    expect($reloaded->hsn_code)->toBe('21069099');
});

it('falls back to the shop default rate when a product has none', function () {
    [$b, $u] = gstShop('12.00');
    $sale = gstSale($b, $u, '112.00', 1, rate: null);

    $line = soleLine(issue($b, $u, $sale));

    expect((string) $line->gst_rate_percent)->toBe('12.00');
    expect((string) $line->taxable_value)->toBe('100.00');
});

it('refuses to invoice the same sale twice', function () {
    [$b, $u] = gstShop();
    $sale = gstSale($b, $u);
    issue($b, $u, $sale);

    expect(fn () => issue($b, $u, $sale))->toThrow(RuntimeException::class);
});

it('refuses to invoice for a shop with no GSTIN', function () {
    [$b, $u] = gstShop(gstin: null);

    expect(fn () => issue($b, $u, gstSale($b, $u)))->toThrow(RuntimeException::class);
});

it('refuses to invoice a reversal', function () {
    [$b, $u] = gstShop();
    $original = gstSale($b, $u);

    $reversal = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $original->customer_id, 'sale_date' => now()->toDateString(),
        'reverses_id' => $original->id,
    ]);
    $reversal->setConnection('pgsql_migrate');
    $reversal->total = '-105.00';
    $reversal->created_by = $u->id;
    $reversal->save();

    expect(fn () => issue($b, $u, $reversal))->toThrow(RuntimeException::class);
});

it('keeps each tenant\'s numbering separate', function () {
    [$mine, $u1] = gstShop();
    [$theirs, $u2] = gstShop();

    issue($mine, $u1, gstSale($mine, $u1));
    issue($mine, $u1, gstSale($mine, $u1));
    $theirFirst = issue($theirs, $u2, gstSale($theirs, $u2));

    expect($theirFirst->seq)->toBe(1);   // not 3
});
