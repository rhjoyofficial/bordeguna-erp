<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * ⚠⚠ THE CASE C-UOM-7 GOT WRONG, AND THIS TEST IS THE CORRECTION.
 *
 * The architecture note said the rounding residue between a converted receipt
 * and its supplier bill would be "routed to the rounding-difference account by
 * LedgerRounding". IT CANNOT BE, AND SAYING SO WAS WRONG.
 *
 * `LedgerRounding::balance` closes a gap INSIDE one journal. Both journals here
 * are internally balanced by construction:
 *
 *   receipt   Dr Stock on Hand      Cr GRNI    - both from the movement's value
 *   bill      Dr GRNI               Cr AP      - both from the bill's own total
 *
 * Neither is unbalanced, so LedgerRounding never fires. The residue - the
 * difference between litres x cost-per-litre and kilograms x cost-per-kilogram -
 * shows up as a NON-ZERO GRNI BALANCE after a fully invoiced delivery.
 *
 * ▶ GRNI is a report, not a suspense account: a balance means "received but not
 * yet invoiced". A residue left there is a small permanent lie about what the
 * business still owes. So the residue must be zero, and this test is what says
 * whether it is rather than assuming.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Oil Co', 'slug' => 'oil-co-grni', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);

    $this->oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $litre->id,
        'purchase_unit_id' => $kg->id,
        'sku' => 'OIL-MUSTARD',
        'name' => 'Mustard Oil',
        'type' => ProductType::FinishedGood,
    ]);

    // ⚠ TEST DATA. The reciprocal of a general edible-oil reference, which Joy
    // said explicitly is NOT the confirmed Bor De Guna factor. No seeder, no
    // default, no production row uses it.
    ProductUnitConversion::create([
        'business_id' => $this->business->id,
        'product_id' => $this->oil->id,
        'from_unit_id' => $kg->id,
        'to_unit_id' => $litre->id,
        'stock_units_per_purchase_unit' => '1.09051254',
        'effective_from' => '2020-01-01',
        'source' => 'PROVISIONAL - general reference, not yet confirmed',
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Ad-Deen Food', 'code' => 'ADDEEN']);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

function receiveAndBill($test, string $kilograms, string $costPerKilogram): void
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $test->oil, 'quantity' => $kilograms, 'unit_cost' => $costPerKilogram]],
        createdBy: $test->user,
    );
    $order->approvalRequest->approve($test->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $line, 'quantity' => $kilograms],
    ], $test->user);

    app(RecordSupplierBill::class)->handle(
        supplier: $test->supplier,
        billNumber: 'INV-'.uniqid(),
        billDate: Carbon::parse('2026-08-09'),
        lines: [['line' => $line, 'quantity' => $kilograms, 'unitCost' => $costPerKilogram]],
        createdBy: $test->user,
        purchaseOrder: $order,
    );
}

it('clears GRNI to zero on a converted delivery', function () {
    receiveAndBill($this, '100', '320');

    // 100 kg x 320 = 32,000.00 invoiced. Stock went in as 109.0513 L at
    // 293.4399 per litre, which values at 31,999.9987 before the ledger rounds
    // it to whole paisa.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3200000)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(3200000);
});

it('clears GRNI to zero across a range of awkward quantities and prices', function (string $kg, string $cost) {
    receiveAndBill($this, $kg, $cost);

    // ⚠⚠ THE ASSERTION THAT MATTERS. If the derived per-litre cost and the
    // invoiced per-kilogram cost disagree by even a paisa, GRNI keeps it and the
    // business's stated liability is permanently wrong by that much.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);
})->with([
    ['7', '333.33'],
    ['13', '271.19'],
    ['99.5', '317.77'],
    ['1000', '319.99'],
    ['0.5', '350'],
    ['2500', '288.88'],
]);

it('keeps every journal internally balanced as well', function () {
    receiveAndBill($this, '1000', '319.99');

    Journal::all()->each(fn (Journal $j) => expect($j->balance()->isZero())->toBeTrue());
});

it('puts the residue in the rounding account where a human can see it', function () {
    // ⚠⚠ C-UOM-7 AS JOY APPROVED IT: the residue is never silently absorbed.
    // Stock is debited with what the movement is worth, GRNI is credited with
    // what the supplier will invoice, and the difference between those two
    // independently computed sides is what LedgerRounding exists to name.
    receiveAndBill($this, '1000', '319.99');

    $rounding = ($this->balanceOn)('rounding_difference');
    $stock = ($this->balanceOn)('stock_on_hand');
    $payable = ($this->balanceOn)('accounts_payable');

    // The residue is real on this quantity, and small.
    expect($rounding)->not->toBe(0)
        ->and(abs($rounding))->toBeLessThanOrEqual(100);

    // ⚠ And it accounts for the whole difference: what the business owes, what
    // it holds, and the named residue add up to nothing left over.
    expect($stock + $payable + $rounding)->toBe(0)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);
});
