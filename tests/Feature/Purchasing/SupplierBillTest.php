<?php

use App\Models\User;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1.3 of Accounting: the supplier's own invoice.
 *
 * ⚠⚠ Accounts Payable is a ledger of BILLS, and the document did not exist. All
 * Purchasing had was `purchase_orders.payment_status` - a bare status string
 * with no amount, no number and no date.
 *
 * ⚠ The bill has its OWN number and its OWN date, and that is the entire point.
 * Goods arrive in one month and the bill arrives in another; the difference
 * between them is Goods Received Not Invoiced, and without it stock exists with
 * no liability behind it and the balance sheet does not balance at month end.
 * That accrual is what makes accrual accounting mean anything.
 *
 * With a bill, three quantities finally coexist on a purchase order line -
 * ordered, received and invoiced. ⚠ Their disagreement is not an error to
 * prevent; it is the normal state of a purchase and the thing accounting
 * reports on.
 *
 * ⛔ NO JOURNAL IS POSTED HERE. Phase 1 builds documents; Phase 3 posts them.
 *
 * See ACCOUNTING-ARCHITECTURE-REVIEW.md §11.4.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bill Co', 'slug' => 'bill-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-BILL',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-08-02 09:00:00');

    $this->receivedOrder = function (string $ordered, string $received, string $cost = '100') {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $ordered, 'unit_cost' => $cost]],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);
        $order = $order->fresh(['lines', 'approvalRequest']);

        app(RecordGoodsReceipt::class)->handle($order, [
            ['line' => $order->lines->first(), 'quantity' => $received],
        ], $this->user);

        return $order->fresh('lines');
    };
});

afterEach(fn () => Carbon::setTestNow());

it('records the supplier own invoice number and date, not ours', function () {
    $order = ($this->receivedOrder)('10', '10');

    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0871',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
        dueDate: '2026-08-27',
    );

    // ⚠ Their number, their date. The bill arriving on the 2nd for goods
    // received in July belongs to July, and only the bill knows that.
    expect($bill->bill_number)->toBe('ACME/2026/0871')
        ->and($bill->bill_date->toDateString())->toBe('2026-07-28')
        ->and($bill->due_date->toDateString())->toBe('2026-08-27')
        ->and((float) $bill->total)->toBe(1000.0)
        ->and($bill->supplier_id)->toBe($this->supplier->id);
});

it('refuses the same invoice number from the same supplier twice', function () {
    // ⚠⚠ The oldest control in accounts payable. A duplicate supplier invoice is
    // how a business pays for the same delivery twice, and it happens by
    // accident far more often than by fraud.
    $order = ($this->receivedOrder)('10', '10');

    $bill = fn () => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0871',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '5', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    $bill();

    expect($bill)->toThrow(InvalidArgumentException::class);
    expect(SupplierBill::count())->toBe(1);
});

it('lets the same number come from a different supplier', function () {
    // Invoice numbering is the supplier's business, not ours. Two of them using
    // "INV-001" is ordinary.
    $order = ($this->receivedOrder)('10', '10');
    $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Beta Traders']);

    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-001',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    app(RecordSupplierBill::class)->handle(
        supplier: $other,
        billNumber: 'INV-001',
        billDate: '2026-07-28',
        lines: [],
        createdBy: $this->user,
    );

    expect(SupplierBill::count())->toBe(2);
});

it('makes ordered, received and invoiced coexist on the order line', function () {
    // ⚠ Three numbers that are allowed to disagree. Ordered 10, received 8,
    // invoiced 8 is a partial delivery correctly billed; the gap between
    // received and invoiced is Goods Received Not Invoiced.
    $order = ($this->receivedOrder)('10', '8');

    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0872',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '8', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    $line = $order->lines->first()->fresh();

    expect((float) $line->quantity_ordered)->toBe(10.0)
        ->and((float) $line->quantity_received)->toBe(8.0)
        ->and((float) $line->quantity_invoiced)->toBe(8.0);
});

it('refuses to invoice more than was received', function () {
    // ⚠ The three-way match. A supplier billing for twelve when eight arrived is
    // the single most common overpayment in a small business, and the system
    // knows what arrived.
    $order = ($this->receivedOrder)('10', '8');

    expect(fn () => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0873',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '12', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    ))->toThrow(InvalidArgumentException::class);

    expect(SupplierBill::count())->toBe(0);
});

it('accumulates across two bills for one delivery', function () {
    $order = ($this->receivedOrder)('10', '10');

    foreach ([['A', '4'], ['B', '6']] as [$suffix, $qty]) {
        app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier,
            billNumber: "ACME/2026/088{$suffix}",
            billDate: '2026-07-28',
            lines: [['line' => $order->lines->first(), 'quantity' => $qty, 'unitCost' => '100']],
            createdBy: $this->user,
            purchaseOrder: $order,
        );
    }

    expect((float) $order->lines->first()->fresh()->quantity_invoiced)->toBe(10.0);

    // And the eleventh unit is refused, because only ten arrived.
    expect(fn () => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0889',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '1', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a bill dated into a closed period', function () {
    // The generalised period lock, now guarding a purchasing document too.
    $order = ($this->receivedOrder)('10', '10');

    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user, 'July close');

    expect(fn () => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'ACME/2026/0890',
        billDate: '2026-07-28',
        lines: [['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => '100']],
        createdBy: $this->user,
        purchaseOrder: $order,
    ))->toThrow(PeriodClosedException::class);
});

it('accepts a bill with no purchase order behind it', function () {
    // ⚠ Most of a small business's spending never sees a purchase order - rent,
    // electricity, fuel, fees. The document has to work without one or it
    // captures a minority of what is actually owed.
    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'UTIL-2026-07',
        billDate: '2026-07-31',
        lines: [],
        createdBy: $this->user,
        note: 'July electricity',
    );

    expect($bill->purchase_order_id)->toBeNull()
        ->and($bill->note)->toBe('July electricity');
});
