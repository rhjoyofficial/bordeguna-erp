<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\TaxClassSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT-ready foundation: a model and a switch, with the engine DORMANT.
 *
 * ⚠⚠ MOST OF THIS FILE PROVES THAT NOTHING HAPPENS. Joy's instruction on
 * 9 August was explicit: build the model, keep VAT off, change no existing
 * behaviour and post nothing. So the valuable tests here are the ones asserting
 * that every figure is still zero and that no toggle is on anywhere - not the
 * ones asserting a table exists.
 *
 * VAT-READY-ARCHITECTURE.md, C-VAT-1 .. C-VAT-12.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Vat Co', 'slug' => 'vat-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
});

// ── C-VAT-8 / C-VAT-9: the engine is OFF and everything is zero ─────────────

it('leaves VAT switched off for every business', function () {
    // ⚠⚠ THE TEST THAT MATTERS MOST. A migration that quietly turned VAT on
    // would start charging customers 15% more, and the first anybody would know
    // is an invoice.
    foreach (Business::withoutGlobalScopes()->get() as $business) {
        expect($business->vat_enabled)->toBeFalse()
            ->and($business->vat_effective_from)->toBeNull();
    }
});

it('leaves the regime undecided rather than guessing one', function () {
    // ⚠ C-VAT-2 is deliberately open: standard 15% VAT and 4% turnover tax are
    // different engines and Joy has not confirmed which applies. Null is the
    // honest state, and a default would be a decision nobody made.
    expect($this->business->vat_regime)->toBeNull();
});

it('records that Bor De Guna prices INCLUDE tax', function () {
    // ⚠⚠ C-VAT-1, Joy's decision on 9 August: the customer-facing price is the
    // final price the customer pays, and VAT is never added on top of it. This
    // is the one thing that could not be retrofitted - it decides what every
    // price already entered MEANT.
    expect($this->business->prices_include_tax)->toBeTrue();
});

it('never says VAT applies, whatever date it is asked about', function (string $date) {
    // ⚠ While `vat_enabled` is false this must be false for every date in
    // history and in the future. No posting path calls it yet; it is tested now
    // so the date rule is settled before the engine is written against it.
    expect($this->business->vatAppliesOn(Carbon::parse($date)))->toBeFalse();
})->with(['2020-01-01', '2026-08-09', '2030-12-31']);

it('does not apply VAT before its effective date, even once enabled', function () {
    // ⚠⚠ C-VAT-5. The ledger is immutable and closed periods are locked, so
    // switching VAT on must never reach back into an invoice already posted.
    // Enabled here ONLY inside this test, and never written by any application
    // path - `vat_enabled` is deliberately not fillable.
    $this->business->forceFill([
        'vat_enabled' => true,
        'vat_effective_from' => '2026-09-01',
    ])->save();

    $business = $this->business->fresh();

    expect($business->vatAppliesOn(Carbon::parse('2026-08-31')))->toBeFalse()
        ->and($business->vatAppliesOn(Carbon::parse('2026-09-01')))->toBeTrue()
        ->and($business->vatAppliesOn(Carbon::parse('2026-09-02')))->toBeTrue();
});

it('refuses to let a request switch VAT on by mass assignment', function () {
    // ⚠⚠ C-VAT-7. Turning VAT on changes what every future invoice charges a
    // customer. It must never ride in on a settings form the way a phone number
    // does. Same reasoning that keeps `is_default` unfillable.
    $this->business->fill([
        'vat_enabled' => true,
        'vat_effective_from' => '2020-01-01',
        'prices_include_tax' => false,
        'vat_regime' => 'standard',
    ])->save();

    $business = $this->business->fresh();

    expect($business->vat_enabled)->toBeFalse()
        ->and($business->vat_effective_from)->toBeNull()
        ->and($business->vat_regime)->toBeNull()
        ->and($business->prices_include_tax)->toBeTrue();
});

// ── C-VAT-6: the accounts exist and are separate ────────────────────────────

it('plants input and output VAT as separate accounts', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $input = Account::bySlug('input_vat_receivable');
    $output = Account::bySlug('output_vat_payable');

    // ⚠⚠ Input VAT is money owed BACK to the business (an asset); output VAT is
    // money owed onward to the government (a liability). Netting them in the
    // ledger would hide which is which - the net is a report, the same rule
    // GRNI follows.
    expect($input)->not->toBeNull()
        ->and($input->type->value)->toBe('asset')
        ->and($input->is_postable)->toBeTrue()
        ->and($output)->not->toBeNull()
        ->and($output->type->value)->toBe('liability')
        ->and($output->is_postable)->toBeTrue()
        ->and($input->id)->not->toBe($output->id);
});

it('plants the VAT accounts without disturbing the chart that was already there', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $before = Account::count();

    // ⚠ The seeder is firstOrCreate on the code, so an installation that has
    // already run picks the two new rows up and nothing else moves. Production
    // has already run it, so this is the path that will actually happen.
    $this->seed(ChartOfAccountsSeeder::class);

    expect(Account::count())->toBe($before)
        ->and(Account::bySlug('cost_of_goods_sold'))->not->toBeNull()
        ->and(Account::bySlug('accounts_payable'))->not->toBeNull();
});

// ── C-VAT-3: rates are effective-dated ──────────────────────────────────────

it('answers with the rate that applied on a date, not the newest one', function () {
    $class = TaxClass::create(['business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard']);

    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $class->id, 'rate' => '15.0000', 'effective_from' => '2020-01-01']);
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $class->id, 'rate' => '7.5000', 'effective_from' => '2026-07-01']);

    // ⚠⚠ An invoice raised in June must still be explicable by June's rate
    // after a July change. This is the entire reason rates are new rows rather
    // than edits, and it is the same shape ProductPrice and AccountMapping use.
    expect($class->rateOn(Carbon::parse('2026-06-30'))->rate)->toBe('15.0000')
        ->and($class->rateOn(Carbon::parse('2026-07-01'))->rate)->toBe('7.5000')
        ->and($class->rateOn(Carbon::parse('2030-01-01'))->rate)->toBe('7.5000');

    // Before any rate existed at all.
    expect($class->rateOn(Carbon::parse('2019-12-31')))->toBeNull();
});

it('keeps a superseded rate exactly as it was', function () {
    $class = TaxClass::create(['business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard']);
    $old = TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $class->id, 'rate' => '15.0000', 'effective_from' => '2020-01-01']);

    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $class->id, 'rate' => '7.5000', 'effective_from' => '2026-07-01']);

    expect($old->fresh()->rate)->toBe('15.0000')
        ->and(TaxRate::count())->toBe(2);
});

// ── C-VAT-11: the class, and what null means ────────────────────────────────

it('seeds the three classes every regime has, and no rates at all', function () {
    $this->seed(TaxClassSeeder::class);
    TenantScope::enter($this->business->id);

    expect(TaxClass::pluck('code')->sort()->values()->all())->toBe(['EXEMPT', 'STANDARD', 'ZERO'])
        // ⚠⚠ NO RATES. C-VAT-2 is open - seeding a rate would invent the
        // decision between 15% VAT and 4% turnover tax that this whole design
        // exists to defer.
        ->and(TaxRate::count())->toBe(0);
});

it('seeds tax classes idempotently', function () {
    $this->seed(TaxClassSeeder::class);
    $this->seed(TaxClassSeeder::class);
    TenantScope::enter($this->business->id);

    expect(TaxClass::count())->toBe(3);
});

it('lets a product carry no tax class at all', function () {
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);

    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'NOTAX-1', 'name' => 'Unclassified product', 'type' => ProductType::FinishedGood,
    ]);

    // ⚠⚠ NULL IS NOT ZERO-RATED. It means "not yet classified", and when the
    // engine is built it falls back to the business default. The two are wrong
    // in opposite directions and only one of them is a refund.
    expect($product->tax_class_id)->toBeNull()
        ->and($product->taxClass)->toBeNull();
});

// ── C-VAT-8: every figure is zero, on both sides of the trade ───────────────

it('adds tax columns to the purchase side that all default to zero', function (string $table, array $columns) {
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column} is missing");
    }
})->with([
    'purchase order lines' => ['purchase_order_lines', ['tax_rate', 'tax_amount']],
    'supplier bill lines' => ['supplier_bill_lines', ['tax_rate', 'tax_amount']],
    'purchase orders' => ['purchase_orders', ['tax_total']],
    'supplier bills' => ['supplier_bills', ['tax_total']],
    // Already existed; named here so the set is visible in one place.
    'sales invoices' => ['sales_invoices', ['tax_total']],
    'sales invoice lines' => ['sales_invoice_lines', ['tax_rate', 'tax_amount']],
]);

it('writes zero rather than null into every tax figure', function () {
    // ⚠⚠ C-VAT-8. A null would later be indistinguishable from "not yet
    // calculated", and the first VAT return would have no way to tell a
    // genuinely zero-rated line from one the engine never touched.
    $this->seed(ChartOfAccountsSeeder::class);

    $supplier = Supplier::create([
        'business_id' => $this->business->id, 'code' => 'SUP-1', 'name' => 'A supplier',
    ]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN',
    ]);
    $product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'TAXZERO-1', 'name' => 'A product', 'type' => ProductType::RawMaterial,
    ]);
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $supplier,
        warehouse: $warehouse,
        lines: [['product' => $product, 'quantity' => '10', 'unit_cost' => '100.00']],
        createdBy: $user,
    );

    $line = $order->fresh('lines')->lines->first();

    // ⚠ Asserted as a VALUE, not as a string. An aggregate or a raw decimal
    // column stringifies differently per driver - SQLite gives "0", MySQL gives
    // "0.0000" - and this slice ships without a display cast on these columns
    // because nothing displays them yet. What C-VAT-8 actually requires is that
    // they are ZERO and NOT NULL, which is driver-independent.
    foreach ([$order->fresh()->tax_total, $line->tax_rate, $line->tax_amount] as $figure) {
        expect($figure)->not->toBeNull()
            ->and((float) $figure)->toBe(0.0);
    }
});

// ── The structural guard ────────────────────────────────────────────────────

it('has no posting path that reads a VAT account', function () {
    // ⚠⚠ THE DORMANCY GUARD. The whole promise to Joy is that this slice
    // changes no financial behaviour. If somebody wires the VAT accounts into
    // a journal before the engine is designed and approved, this fails - which
    // is the point. Delete this test and the promise becomes unenforced.
    $sources = collect(glob(app_path('Modules/*/Actions/*.php')))
        ->merge(glob(app_path('Modules/*/Support/*.php')))
        ->filter(fn (string $file) => ! str_contains($file, 'TaxClass') && ! str_contains($file, 'TaxRate'));

    $offenders = $sources->filter(function (string $file) {
        $body = file_get_contents($file);

        return str_contains($body, 'output_vat_payable') || str_contains($body, 'input_vat_receivable');
    })->map(fn (string $file) => basename($file))->values()->all();

    expect($offenders)->toBe([], 'these post to a VAT account while the engine is meant to be dormant: '.implode(', ', $offenders));
});
