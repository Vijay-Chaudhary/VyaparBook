<?php
// tests/Feature/Web/InvoicesTest.php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A registered shop with one GST-inclusive sale of ₹105 at 5%. */
function invShop(array $overrides = []): array
{
    [$owner, $business] = pwOwner();

    // $overrides first: PHP's + keeps the LEFT operand, so defaults must be
    // on the right or an override is silently ignored.
    DB::table('businesses')->where('id', $business->id)->update(
        $overrides + ['gstin' => '09ABCDE1234F1Z5', 'default_gst_rate_percent' => '5.00', 'state_code' => '09']
    );

    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'Bhujia', 'name_en' => 'Bhujia',
    ]);
    $product->hsn_code = '21069099';
    $product->save();

    $size = PackSize::create([
        'business_id' => $business->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $pack = ProductPack::create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '105.00',
    ]);
    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ramesh Traders', 'village' => 'Rampur', 'opening_balance' => '0.00',
    ]);

    $sale = new Sale([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(),
    ]);
    $sale->total = '105.00';
    $sale->created_by = $owner->id;
    $sale->save();

    $line = new SaleLine([
        'business_id' => $business->id, 'sale_id' => $sale->id,
        'product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '105.00',
    ]);
    $line->line_total = '105.00';
    $line->save();

    return [$owner, $business, $sale, $product];
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/invoices')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())->get('/invoices')->assertRedirect(route('app'));
    });
});

describe('issuing', function () {
    it('links the customer on an uninvoiced sale through to their khata', function () {
        [$owner, $business] = invShop();
        $customer = Customer::where('business_id', $business->id)->firstOrFail();

        $this->actingAs($owner)->get('/invoices?business=' . $business->id)
            ->assertOk()
            ->assertSee(route('customers.show', [
                'customer' => $customer->id, 'business' => $business->id,
            ]), false);
    });

    it('lists sales that still need an invoice', function () {
        [$owner, $business] = invShop();

        $this->actingAs($owner)->get('/invoices?business=' . $business->id)
            ->assertOk()
            ->assertSee('Ramesh Traders')
            ->assertSee('₹105.00');
    });

    it('issues a numbered invoice from a sale', function () {
        [$owner, $business, $sale] = invShop();

        $this->actingAs($owner)->post('/invoices', [
            'business' => $business->id, 'sale' => $sale->id, 'buyer_gstin' => '09ZZZZZ9999Z1Z9',
        ])->assertRedirect();

        $invoice = Invoice::where('business_id', $business->id)->sole();
        expect($invoice->number)->toEndWith('/0001');
        expect($invoice->buyer_gstin)->toBe('09ZZZZZ9999Z1Z9');
        expect((string) $invoice->grand_total)->toBe('105.00');
        expect((string) $invoice->taxable_total)->toBe('100.00');
    });

    it('does not change what the customer owes', function () {
        [$owner, $business, $sale] = invShop();
        $before = (string) Sale::find($sale->id)->total;

        $this->actingAs($owner)->post('/invoices', ['business' => $business->id, 'sale' => $sale->id]);

        // The whole point of extracting tax rather than adding it.
        expect((string) Sale::find($sale->id)->total)->toBe($before);
    });

    it('refuses a shop with no GSTIN and explains why', function () {
        [$owner, $business, $sale] = invShop(['gstin' => null]);

        $this->actingAs($owner)->post('/invoices', ['business' => $business->id, 'sale' => $sale->id])
            ->assertRedirect();

        expect(DB::table('invoices')->where('business_id', $business->id)->count())->toBe(0);
    });

    it('refuses to invoice another tenant\'s sale', function () {
        [$owner, $business] = invShop();
        [, , $theirSale] = invShop();

        $this->actingAs($owner)->post('/invoices', ['business' => $business->id, 'sale' => $theirSale->id])
            ->assertNotFound();
    });
});

describe('print view', function () {
    it('shows everything a tax invoice must carry', function () {
        [$owner, $business, $sale] = invShop();
        $this->actingAs($owner)->post('/invoices', [
            'business' => $business->id, 'sale' => $sale->id, 'buyer_gstin' => '09ZZZZZ9999Z1Z9',
        ]);
        $invoice = Invoice::where('business_id', $business->id)->sole();

        $this->actingAs($owner)->get('/invoices/' . $invoice->id . '?business=' . $business->id)
            ->assertOk()
            ->assertSee($invoice->number)          // invoice number
            ->assertSee('09ABCDE1234F1Z5')         // seller GSTIN
            ->assertSee('09ZZZZZ9999Z1Z9')         // buyer GSTIN
            ->assertSee('Ramesh Traders')
            ->assertSee('21069099')                // HSN
            ->assertSee('₹100.00')                 // taxable value
            ->assertSee('₹2.50')                   // CGST and SGST
            ->assertSee('₹105.00');                // grand total
    });

    it('does not show another tenant an invoice', function () {
        [$owner, $business] = invShop();
        [$otherOwner, $otherBusiness, $otherSale] = invShop();
        $this->actingAs($otherOwner)->post('/invoices', ['business' => $otherBusiness->id, 'sale' => $otherSale->id]);
        $theirs = Invoice::where('business_id', $otherBusiness->id)->sole();

        $this->actingAs($owner)->get('/invoices/' . $theirs->id . '?business=' . $business->id)
            ->assertNotFound();
    });
});

describe('gst settings', function () {
    it('saves the shop default rate and state code', function () {
        [$owner, $business] = invShop();

        $this->actingAs($owner)->post('/gst', [
            'business' => $business->id,
            'default_gst_rate_percent' => '12.00',
            'state_code' => '27',
        ])->assertRedirect(route('gst', ['business' => $business->id]));

        $fresh = DB::table('businesses')->where('id', $business->id)->first();
        expect((string) $fresh->default_gst_rate_percent)->toBe('12.00');
        expect($fresh->state_code)->toBe('27');
    });

    it('saves per-product HSN and rate', function () {
        [$owner, $business, , $product] = invShop();

        $this->actingAs($owner)->post('/gst', [
            'business' => $business->id,
            'default_gst_rate_percent' => '5.00',
            'state_code' => '09',
            'products' => [$product->id => ['hsn_code' => '19059090', 'gst_rate_percent' => '18.00']],
        ])->assertRedirect();

        $fresh = DB::table('products')->where('id', $product->id)->first();
        expect($fresh->hsn_code)->toBe('19059090');
        expect((string) $fresh->gst_rate_percent)->toBe('18.00');
    });

    it('rejects an impossible GST rate', function () {
        [$owner, $business] = invShop();

        $this->actingAs($owner)->post('/gst', [
            'business' => $business->id, 'default_gst_rate_percent' => '150', 'state_code' => '09',
        ])->assertSessionHasErrors('default_gst_rate_percent');
    });
});
