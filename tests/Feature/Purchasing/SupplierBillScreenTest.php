<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The supplier bill, driven from the browser.
 *
 * ⚠⚠ The engine was complete and tested for two weeks with no way to reach it,
 * so GRNI could never clear and Accounts Payable was never created from a
 * purchase. These are the tests that say the feature EXISTS, as opposed to
 * being merely implemented.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Bill Screen Co', 'slug' => 'bill-screen-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    $this->seed(PurchasingPermissionsSeeder::class);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mohammadpur', 'code' => 'MOH']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'OIL-BULK',
        'name' => 'Mustard oil bulk',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'name' => 'Razzak Oil Mill',
        'code' => 'SUP-RZK',
        'payment_terms' => '30 days',
    ]);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->asManager = fn () => $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id]);

    $this->order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '320.00']],
        createdBy: $this->manager,
    );
    approveIfPending($this->order->approvalRequest, $this->manager);
    $this->order = $this->order->fresh(['lines', 'approvalRequest']);
    $this->line = $this->order->lines->first();

    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '100'],
    ], $this->manager);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    $this->payload = fn (array $overrides = []) => array_merge([
        'purchase_order_id' => $this->order->id,
        'bill_number' => 'RZK-1001',
        'bill_date' => '2026-08-03',
        'due_date' => '2026-09-02',
        'lines' => [[
            'purchase_order_line_id' => $this->line->id,
            'quantity' => '100',
            'unit_cost' => '320.00',
        ]],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

it('shows the bills list and the orders still waiting to be billed', function () {
    ($this->asManager)()->get('/purchasing/bills')
        ->assertOk()
        ->assertSee($this->order->po_number);
});

it('opens the create form against one purchase order', function () {
    ($this->asManager)()->get("/purchasing/bills/new/{$this->order->id}")->assertOk();
});

// ▶▶ THE MILESTONE: the whole cycle, from the browser.
it('records a bill that clears GRNI and creates the payable', function () {
    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)());

    $bill = SupplierBill::sole();

    $response->assertRedirect(route('purchasing.bills.show', $bill->id));

    expect((float) $bill->total)->toBe(32000.0)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3200000)
        ->and($bill->due_date?->toDateString())->toBe('2026-09-02');
});

it('shows the bill with its journal', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)());

    ($this->asManager)()->get('/purchasing/bills/'.SupplierBill::sole()->id)->assertOk();
});

it('records the variance when the invoice differs from the order', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [[
            'purchase_order_line_id' => $this->line->id,
            'quantity' => '100',
            'unit_cost' => '330.00',
        ]],
    ]));

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3300000);
});

it('refuses over-billing with a readable message', function () {
    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [[
            'purchase_order_line_id' => $this->line->id,
            'quantity' => '150',
            'unit_cost' => '320.00',
        ]],
    ]));

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('only 100')
        ->and(SupplierBill::count())->toBe(0);
});

it('refuses a duplicate invoice number from the same supplier', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '40', 'unit_cost' => '320.00']],
    ]));

    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '40', 'unit_cost' => '320.00']],
    ]));

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('already been recorded')
        ->and(SupplierBill::count())->toBe(1);
});

it('bills partially and then bills the rest', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'bill_number' => 'RZK-PART-1',
        'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '40', 'unit_cost' => '320.00']],
    ]));

    ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'bill_number' => 'RZK-PART-2',
        'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '60', 'unit_cost' => '320.00']],
    ]));

    expect(SupplierBill::count())->toBe(2)
        ->and((float) $this->line->fresh()->quantity_invoiced)->toBe(100.0)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);
});

// ▶▶ D-SB-8, the focused case Joy asked for by name.
it('will not bill goods that were received and then returned', function () {
    app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line->fresh(), 'quantity' => '30'],
    ], $this->manager);

    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '80', 'unit_cost' => '320.00']],
    ]));

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('only 70');
});

it('refuses a line belonging to a different purchase order', function () {
    $other = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '320.00']],
        createdBy: $this->manager,
    );
    approveIfPending($other->approvalRequest, $this->manager);
    $otherLine = $other->fresh('lines')->lines->first();

    app(RecordGoodsReceipt::class)->handle($other->fresh('lines'), [
        ['line' => $otherLine, 'quantity' => '10'],
    ], $this->manager);

    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'lines' => [['purchase_order_line_id' => $otherLine->id, 'quantity' => '10', 'unit_cost' => '320.00']],
    ]));

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('different purchase order')
        ->and(SupplierBill::count())->toBe(0);
});

it('refuses a bill dated into a closed month on the date field', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->manager);

    $response = ($this->asManager)()->post('/purchasing/bills', ($this->payload)());

    $response->assertSessionHasErrors('bill_date');
    expect(SupplierBill::count())->toBe(0);
});

it('keeps Staff out of recording a bill', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/purchasing/bills', ($this->payload)())
        ->assertForbidden();

    expect(SupplierBill::count())->toBe(0);
});

it('lets anyone in the business read the bill list', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/purchasing/bills')
        ->assertOk();
});

// ⚠⚠ Tenant isolation: another business's purchase order must be unreachable,
// not merely unlisted.
it('refuses a purchase order belonging to another business', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co', 'fiscal_year_start_month' => 7]);

    app(BusinessContext::class)->set($other->id);
    $otherSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'Someone Else', 'code' => 'SUP-X']);
    $otherWarehouse = Warehouse::create(['business_id' => $other->id, 'name' => 'Far', 'code' => 'FAR']);
    $otherUnit = UnitOfMeasure::create(['business_id' => $other->id, 'name' => 'Kg', 'abbreviation' => 'kg']);
    $otherProduct = Product::create([
        'business_id' => $other->id,
        'unit_of_measure_id' => $otherUnit->id,
        'sku' => 'X-1',
        'name' => 'Theirs',
        'type' => ProductType::RawMaterial,
    ]);
    $otherOrder = app(CreatePurchaseOrder::class)->handle(
        supplier: $otherSupplier,
        warehouse: $otherWarehouse,
        lines: [['product' => $otherProduct, 'quantity' => '5', 'unit_cost' => '10.00']],
        createdBy: $this->manager,
    );

    app(BusinessContext::class)->set($this->business->id);

    ($this->asManager)()->post('/purchasing/bills', ($this->payload)([
        'purchase_order_id' => $otherOrder->id,
    ]))->assertNotFound();

    expect(SupplierBill::count())->toBe(0);
});

it('reverses a bill from the browser and makes the goods billable again', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)());
    $bill = SupplierBill::sole();

    ($this->asManager)()->post("/purchasing/bills/{$bill->id}/reverse", ['note' => 'Wrong invoice'])
        ->assertRedirect();

    expect($bill->fresh()->isReversed())->toBeTrue()
        ->and((float) $this->line->fresh()->quantity_invoiced)->toBe(0.0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0);
});

// ⚠ D-SB-6: no edit, no delete. Their absence is the guard.
it('offers no way to edit or delete a posted bill', function () {
    ($this->asManager)()->post('/purchasing/bills', ($this->payload)());
    $bill = SupplierBill::sole();

    // ⚠ 405 rather than 404, and that is the stronger answer: the URL exists
    // and is readable, but there is no verb on it that edits or removes a
    // posted bill. Correction is the reversal below it.
    ($this->asManager)()->put("/purchasing/bills/{$bill->id}", ['bill_number' => 'HACKED'])
        ->assertMethodNotAllowed();
    ($this->asManager)()->delete("/purchasing/bills/{$bill->id}")->assertMethodNotAllowed();

    expect($bill->fresh()->bill_number)->toBe('RZK-1001');
});

// ⚠ PUR-009 (audit 14 Oct 2026): a database error is a RuntimeException, and
// this screen showed the caught message, so a unique-index refusal (two people
// saving the same bill number in the same second) printed the SQL statement
// under the form. Now a sentence; the SQL never reaches the page.
it('shows a sentence, never SQL, when the database refuses a bill (PUR-009)', function () {
    $this->mock(RecordSupplierBill::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(
        new UniqueConstraintViolationException('sqlite', 'insert into "supplier_bills" ("bill_number") values (?)', ['INV-1'], new PDOException('SQLSTATE[23000]: Integrity constraint violation')),
    ));

    ($this->asManager)()->post('/purchasing/bills', ($this->payload)())
        ->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->not->toContain('SQLSTATE')
        ->and(session('errors')->first('lines'))->not->toContain('insert into')
        ->and(session('errors')->first('lines'))->toContain('Somebody saved the same document');
});
