<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The purchase order screens (SALES-UX-AUDIT #11): the unit and last received
 * cost beside every quantity, the fake "Payment: Paid" control gone, and buttons
 * that walk the chain order -> bill -> payment instead of leaving the clerk to
 * find each next screen from the menu.
 *
 * ⚠ The buttons are asserted through the PROPS they are gated on, by name. The
 * supplier payment screen once shipped with its only way in reading a prop that
 * did not exist, and every test was green because they all posted to the route
 * directly (SupplierPaymentScreenTest).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    $this->business = Business::create(['name' => 'Chain Co', 'slug' => 'chain-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Chain Co', 'slug' => 'rival-chain-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->staff->assignRole('Staff');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->ghee = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'GHE-BULK',
        'name' => 'Cow ghee, bulk',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Pabna Dairy']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'City Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->orderOf = function (string $quantity, string $cost): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->ghee, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->owner,
        );
        approveIfPending($order->approvalRequest, $this->owner);

        return $order->fresh(['lines', 'approvalRequest']);
    };

    $this->receiveAll = function (PurchaseOrder $order): void {
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->owner);
    };

    $this->billAll = function (PurchaseOrder $order, string $number = 'PD-101'): SupplierBill {
        $line = $order->lines->first()->fresh();

        return app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier,
            billNumber: $number,
            billDate: Carbon::parse('2026-09-21'),
            lines: [['line' => $line, 'quantity' => (string) $line->quantity_received, 'unitCost' => (string) $line->unit_cost]],
            createdBy: $this->owner,
            purchaseOrder: $order,
        );
    };

    $this->pay = fn (SupplierBill $bill, string $amount) => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-09-21',
        allocations: [['bill' => $bill, 'amount' => $amount]],
        createdBy: $this->owner,
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id]);
    $this->asStaff = fn () => $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// The fake payment control
// ---------------------------------------------------------------------------

/**
 * ⚠⚠ AUDIT #11, VERIFIED BEFORE REMOVING. The dropdown posted to
 * `purchase-orders.payment-status`, whose action wrote `payment_status` on the
 * order and nothing else: no bill, no payment, no journal. So an order could
 * read "Paid" while Accounts Payable owed the supplier its full value.
 */
it('no longer offers a way to type a payment status onto an order', function () {
    $order = ($this->orderOf)('10', '900');

    expect(Route::has('purchasing.purchase-orders.payment-status'))->toBeFalse();

    $response = ($this->asOwner)()->post("/purchasing/purchase-orders/{$order->id}/payment-status", ['payment_status' => 'paid']);
    expect($response->status())->toBeIn([404, 405]);

    expect($order->fresh()->payment_status->value)->toBe('unpaid');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page
            ->missing('can_update_payment')
            ->missing('order.payment_status')
        );
});

it('derives what is owed on an order from its bills and payments', function () {
    $order = ($this->orderOf)('10', '900');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('billing.state', 'nothing_billed')->has('bills', 0));

    ($this->receiveAll)($order);
    $bill = ($this->billAll)($order->fresh('lines'));

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('billing.state', 'owed')
            ->where('billing.billed', '9000.00')
            ->where('billing.outstanding', '9000.00')
            ->has('bills', 1)
            ->where('bills.0.bill_number', 'PD-101')
            ->where('bills.0.outstanding', '9000.00')
        );

    ($this->pay)($bill, '4000');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data.0.billing.state', 'owed')
            ->where('orders.data.0.billing.outstanding', '5000.00')
            ->missing('orders.data.0.payment_status')
        );

    ($this->pay)($bill->fresh(), '5000');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page->where('orders.data.0.billing.state', 'settled'));
});

// ---------------------------------------------------------------------------
// Unit and last received cost
// ---------------------------------------------------------------------------

it('shows the unit and what the product last arrived at on another order', function () {
    $earlier = ($this->orderOf)('10', '880.5');
    ($this->receiveAll)($earlier);

    // Ordered but never delivered: an offer nobody honoured is not a cost.
    ($this->orderOf)('10', '999');

    $current = ($this->orderOf)('20', '900');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $current))
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.product.unit_of_measure.abbreviation', 'kg')
            ->where("last_costs.{$this->ghee->id}.unit_cost", '880.50')
            ->where("last_costs.{$this->ghee->id}.po_number", $earlier->po_number)
            ->where("last_costs.{$this->ghee->id}.received_on", '2026-09-21')
        );

    ($this->asOwner)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.0.unit', 'kg')
            ->where('products.0.last_cost.unit_cost', '880.50')
        );
});

it('leaves an order\'s own receipt out of its "last received" figure', function () {
    $order = ($this->orderOf)('10', '900');
    ($this->receiveAll)($order);

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->missing("last_costs.{$this->ghee->id}"));
});

it('keeps all four places of a unit cost rather than rounding it to paisa', function () {
    $order = ($this->orderOf)('1000', '0.4567');
    ($this->receiveAll)($order);

    ($this->asOwner)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page->where('products.0.last_cost.unit_cost', '0.4567'));
});

// ⚠⚠ The ~9% trap. A cost per kilogram shown beside a box that now asks for
// litres is worse than no figure.
it('withholds the last cost once the product is bought in a different unit', function () {
    $earlier = ($this->orderOf)('10', '880');
    ($this->receiveAll)($earlier);

    $litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->ghee->forceFill(['purchase_unit_id' => $litre->id])->save();

    ($this->asOwner)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('products.0.unit', 'L')
            ->where('products.0.last_cost', null)
        );
});

// ---------------------------------------------------------------------------
// Order -> bill -> payment
// ---------------------------------------------------------------------------

it('offers "Record the supplier bill" only while received goods are unbilled, and only to who may bill', function () {
    $order = ($this->orderOf)('10', '900');

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can_record_bill', false));

    ($this->receiveAll)($order);

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can_record_bill', true));

    // Staff receive goods but do not create liabilities (D-SB-5).
    ($this->asStaff)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can_record_bill', false)->where('can_record_payment', false));

    ($this->billAll)($order->fresh('lines'));

    ($this->asOwner)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('can_record_bill', false)->where('can_record_payment', true));
});

it('opens the bill form prefilled with exactly what was received', function () {
    $order = ($this->orderOf)('10', '900');
    $line = $order->lines->first();
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '6']], $this->owner);

    ($this->asOwner)()->get(route('purchasing.bills.create', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/bills/create')
            ->where('order.lines.0.billable', '6.0000')
            ->where('order.lines.0.order_unit_cost', '900.0000')
        );
});

it('offers "Pay this bill" on a bill that still owes, and not once it is paid', function () {
    $order = ($this->orderOf)('10', '900');
    ($this->receiveAll)($order);
    $bill = ($this->billAll)($order->fresh('lines'));

    ($this->asOwner)()->get(route('purchasing.bills.show', $bill))
        ->assertInertia(fn ($page) => $page
            ->where('can_pay', true)
            ->where('bill.purchase_order_id', $order->id)
        );

    ($this->asStaff)()->get(route('purchasing.bills.show', $bill))
        ->assertInertia(fn ($page) => $page->where('can_pay', false));

    ($this->pay)($bill, '9000');

    ($this->asOwner)()->get(route('purchasing.bills.show', $bill))
        ->assertInertia(fn ($page) => $page->where('can_pay', false));
});

it('opens the payment form with the bill\'s supplier chosen and its outstanding typed in', function () {
    $order = ($this->orderOf)('10', '900');
    ($this->receiveAll)($order);
    $bill = ($this->billAll)($order->fresh('lines'));
    ($this->pay)($bill, '2500');

    ($this->asOwner)()->get(route('purchasing.payments.create', ['bill_id' => $bill->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/payments/create')
            ->where('selectedSupplierId', $this->supplier->id)
            ->has('bills', 1)
            ->where('prefill.bill_id', $bill->id)
            ->where('prefill.amount', '6500.00')
        );
});

it('prefills nothing for a bill that is settled, reversed, or belongs to another business', function () {
    $order = ($this->orderOf)('10', '900');
    ($this->receiveAll)($order);
    $bill = ($this->billAll)($order->fresh('lines'));
    app(ReverseSupplierBill::class)->handle($bill, $this->owner, 'Wrong supplier invoice');

    ($this->asOwner)()->get(route('purchasing.payments.create', ['bill_id' => $bill->id]))
        ->assertInertia(fn ($page) => $page->where('prefill', null));

    // Another business's bill: the scoped lookup finds nothing.
    TenantScope::enter($this->other->id);
    $foreignSupplier = Supplier::create(['business_id' => $this->other->id, 'name' => 'Foreign Dairy']);
    $foreign = SupplierBill::create([
        'business_id' => $this->other->id,
        'supplier_id' => $foreignSupplier->id,
        'bill_number' => 'FX-1',
        'bill_date' => '2026-09-21',
        'total' => '500',
        'created_by' => $this->owner->id,
    ]);
    TenantScope::enter($this->business->id);

    ($this->asOwner)()->get(route('purchasing.payments.create', ['bill_id' => $foreign->id]))
        ->assertInertia(fn ($page) => $page->where('prefill', null)->where('selectedSupplierId', null));
});

/**
 * ⚠⚠ FOUND WHILE BUILDING "Pay this bill". A reversed bill kept reporting its
 * full total as outstanding, so it sat on the payment form and in the
 * supplier's amount owed, and RecordSupplierPayment would pay it: a debit to
 * Accounts Payable with nothing behind it, since the reversal had already taken
 * the liability off. Proven red before SupplierBill::outstanding() was fixed.
 */
it('treats a reversed bill as owing nothing, so it can be neither offered nor paid', function () {
    $order = ($this->orderOf)('10', '900');
    ($this->receiveAll)($order);
    $bill = ($this->billAll)($order->fresh('lines'));
    app(ReverseSupplierBill::class)->handle($bill, $this->owner, 'Wrong supplier invoice');

    expect($bill->fresh()->outstanding()->minor)->toBe(0);

    ($this->asOwner)()->get(route('purchasing.payments.create', ['supplier_id' => $this->supplier->id]))
        ->assertInertia(fn ($page) => $page->has('bills', 0)->has('suppliers', 0));

    ($this->asOwner)()->post('/purchasing/payments', [
        'supplier_id' => $this->supplier->id,
        'money_account_id' => $this->bank->id,
        'paid_on' => '2026-09-21',
        'allocations' => [['supplier_bill_id' => $bill->id, 'amount' => '9000']],
    ])->assertSessionHasErrors('allocations.0.amount');

    expect(SupplierPayment::count())->toBe(0);

    ($this->asOwner)()->get(route('purchasing.bills.show', $bill))
        ->assertInertia(fn ($page) => $page->where('can_pay', false)->where('bill.outstanding', '0.00'));
});
