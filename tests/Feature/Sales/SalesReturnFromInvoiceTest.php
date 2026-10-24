<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnAllocation;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B20 (24 Oct 2026), "Managing Sales Returns": "You open
 * the original Sales Invoice and click Create Return". Good stock goes back to
 * a selling warehouse and the due is wiped; damaged stock goes to the
 * Damaged/Scrap (quarantine) warehouse and the customer is credited; an
 * exchange is "Step 1 the return, Step 2 a brand new invoice for the
 * replacement", never mixed.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Returns B20 Co', 'slug' => 'returns-b20-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $this->gulshan = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Gulshan', 'code' => 'GUL']);
    $this->quarantine = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Damaged / Quarantine', 'code' => 'QRN', 'not_for_sale' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-OIL', 'name' => 'Mustard Oil 1L', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Dealer]);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash in Hand', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id, 'is_active' => true,
    ]);

    Carbon::setTestNow('2026-10-24 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    app(RecordOpeningStock::class)->handle($this->main, [[
        'product' => $this->product, 'quantity' => '100', 'unitCost' => '500',
    ]], $this->owner, asAt: '2026-10-01 09:00:00');

    $this->sell = function (string $quantity = '10'): array {
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->main,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $this->owner,
        );
        $invoice = app(RecordSalesInvoice::class)->handle(
            customer: $this->customer,
            lines: [['deliveryLine' => $delivery->lines->first(), 'quantity' => $quantity, 'unitPrice' => '850']],
            createdBy: $this->owner,
        );

        return [$delivery, $invoice];
    };

    $this->pay = fn (SalesInvoice $invoice) => app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $this->cash,
        receivedOn: '2026-10-24',
        allocations: [['invoice' => $invoice, 'amount' => (string) $invoice->total]],
        createdBy: $this->owner,
    );

    $this->returnPayload = fn (Delivery $delivery, SalesInvoice $invoice, string $good, string $damaged, array $extra = []) => array_merge([
        'delivery_id' => $delivery->id,
        'sales_invoice_id' => $invoice->id,
        'returned_at' => '2026-10-24',
        'lines' => [[
            'delivery_line_id' => $delivery->lines->first()->id,
            'quantity_to_stock' => $good,
            'quantity_to_quarantine' => $damaged,
        ]],
    ], $extra);

    $this->inWarehouse = fn (Warehouse $warehouse) => (float) StockBatch::where('warehouse_id', $warehouse->id)->sum('current_quantity');
    $this->balanced = fn () => [(int) JournalLine::query()->sum('amount_minor'), SubledgerReconciliation::reconcileAllBusinesses()['problems']];
});

afterEach(fn () => Carbon::setTestNow());

it('opens the return form from the invoice, with the batch the sale took and the billed price', function () {
    [$delivery, $invoice] = ($this->sell)();
    $batch = StockBatch::where('warehouse_id', $this->main->id)->sole();

    ($this->asRole)('Staff')->get('/sales/returns/create?invoice_id='.$invoice->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/returns/create')
            ->where('deliveryId', $delivery->id)
            ->where('invoice.invoice_number', $invoice->invoice_number)
            ->where('invoice.outstanding', '8500.00')
            ->where('deliveryWarehouseId', $this->main->id)
            ->where('lines.0.quantity_returnable', '10.0000')
            ->where('lines.0.unit_price', '850.00')
            ->where('lines.0.credit_per_unit', '850.00')
            ->where('lines.0.batches.0.batch_number', $batch->batch_number)
            ->where('can_exchange', true)
            // A Not for sale warehouse is never where good goods go.
            ->where('sellableWarehouses', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Gulshan', 'Main']));
});

it('wipes the due on an unpaid invoice: good goods to the chosen warehouse, damaged to quarantine, same batch and cost', function () {
    [$delivery, $invoice] = ($this->sell)();

    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '3', '2', ['stock_warehouse_id' => $this->gulshan->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $return = SalesReturn::query()->sole();
    $source = StockBatch::where('warehouse_id', $this->main->id)->sole();

    expect($return->sales_invoice_id)->toBe($invoice->id)
        ->and($return->stock_warehouse_id)->toBe($this->gulshan->id)
        ->and($return->quarantine_warehouse_id)->toBe($this->quarantine->id)
        ->and((float) $return->credit_total)->toBe(4250.0)
        // The credit reduced what they owe: 8,500 less 5 x 850.
        ->and($invoice->fresh()->outstanding()->toTaka())->toBe('4250.00')
        ->and(($this->inWarehouse)($this->main))->toBe(90.0)
        ->and(($this->inWarehouse)($this->gulshan))->toBe(3.0)
        ->and(($this->inWarehouse)($this->quarantine))->toBe(2.0)
        ->and(StockBatch::where('warehouse_id', $this->gulshan->id)->sole()->batch_number)->toBe($source->batch_number)
        ->and((float) StockBatch::where('warehouse_id', $this->gulshan->id)->sole()->unit_cost)->toBe(500.0)
        ->and(($this->balanced)())->toBe([0, []]);
});

it('refunds a paid invoice split over two accounts, the blank row taking what is left', function () {
    [$delivery, $invoice] = ($this->sell)();
    ($this->pay)($invoice);

    ($this->asRole)('Manager')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '4', '0', [
        'refunds' => [
            ['money_account_id' => $this->cash->id, 'amount' => '1000'],
            ['money_account_id' => $this->bank->id],
        ],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $return = SalesReturn::query()->sole();

    expect(CustomerRefund::query()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([1000.0, 2400.0])
        ->and($return->refundable()->toTaka())->toBe('0.00')
        ->and(($this->inWarehouse)($this->main))->toBe(94.0)
        ->and(($this->balanced)())->toBe([0, []]);
});

it('refuses a cash refund from Staff and records nothing', function () {
    [$delivery, $invoice] = ($this->sell)();
    ($this->pay)($invoice);

    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '4', '0', [
        'refunds' => [['money_account_id' => $this->cash->id]],
    ]))->assertForbidden();

    expect(SalesReturn::count())->toBe(0)->and(CustomerRefund::count())->toBe(0);
});

it('keeps a paid invoice credit open, then uses it on the next invoice, once', function () {
    [$delivery, $invoice] = ($this->sell)();
    ($this->pay)($invoice);
    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '2', '0'))->assertSessionHasNoErrors();

    $return = SalesReturn::query()->sole();
    [, $next] = ($this->sell)('3');

    expect($return->refundable()->toTaka())->toBe('1700.00');

    // The return page offers the next open invoice.
    ($this->asRole)('Manager')->get("/sales/returns/{$return->id}")
        ->assertInertia(fn ($page) => $page->where('openInvoices.0.invoice_number', $next->invoice_number));

    // Staff may not move credit between bills.
    ($this->asRole)('Staff')->post("/sales/returns/{$return->id}/apply-credit", [
        'sales_invoice_id' => $next->id, 'amount' => '1700', 'open_seen' => '1700.00',
    ])->assertForbidden();

    $manager = ($this->asRole)('Manager');
    $manager->post("/sales/returns/{$return->id}/apply-credit", [
        'sales_invoice_id' => $next->id, 'amount' => '1700', 'open_seen' => '1700.00',
    ])->assertSessionHasNoErrors();

    // The same click again: the page's open credit is stale, so it is refused.
    $manager->post("/sales/returns/{$return->id}/apply-credit", [
        'sales_invoice_id' => $next->id, 'amount' => '1700', 'open_seen' => '1700.00',
    ])->assertSessionHasErrors('amount');

    expect($next->fresh()->outstanding()->toTaka())->toBe('850.00')
        ->and($return->fresh()->refundable()->toTaka())->toBe('0.00')
        ->and(SalesReturnAllocation::where('sales_invoice_id', $next->id)->count())->toBe(1)
        // No new journal: the return already credited the receivable.
        ->and(Journal::query()->where('source_type', (new SalesReturn)->getMorphClass())->count())->toBe(1)
        ->and(($this->balanced)())->toBe([0, []]);
});

it('opens the replacement order of an exchange, linked to the return, once', function () {
    [$delivery, $invoice] = ($this->sell)();

    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '0', '1', ['exchange' => true]))
        ->assertRedirectContains('/sales/orders?exchange=');

    $return = SalesReturn::query()->sole();
    $staff = ($this->asRole)('Staff');

    $staff->get('/sales/orders?exchange='.$return->id)
        ->assertInertia(fn ($page) => $page
            ->where('exchange_order.customer_id', $this->customer->id)
            ->where('exchange_order.warehouse_id', $this->main->id)
            ->where('exchange_order.lines.0.product_id', $this->product->id)
            ->where('exchange_order.lines.0.quantity', '1')
            ->where('exchange_order.exchange_for.return_number', $return->return_number));

    $order = [
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->main->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '1']],
        'exchange_for_sales_return_id' => $return->id,
    ];

    $staff->post('/sales/orders', $order)->assertSessionHasNoErrors();
    $staff->post('/sales/orders', $order)->assertSessionHasErrors('exchange_for_sales_return_id');

    expect(SalesOrder::query()->sole()->exchange_for_sales_return_id)->toBe($return->id)
        // Once replaced, the return offers no second exchange.
        ->and($staff->get('/sales/orders?exchange='.$return->id)->original->getData()['page']['props']['exchange_order'])->toBeNull();
});

it('refuses good goods into a Not for sale warehouse, and an invoice that did not bill the challan', function () {
    [$delivery, $invoice] = ($this->sell)();
    [, $other] = ($this->sell)('2');

    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $invoice, '1', '0', ['stock_warehouse_id' => $this->quarantine->id]))
        ->assertSessionHasErrors('stock_warehouse_id');

    ($this->asRole)('Staff')->post('/sales/returns', ($this->returnPayload)($delivery, $other, '1', '0'))
        ->assertSessionHasErrors('sales_invoice_id');

    expect(SalesReturn::count())->toBe(0)
        ->and(($this->inWarehouse)($this->main))->toBe(88.0);
});

it('sends a refused parcel\'s broken goods to quarantine', function () {
    $courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->main,
        lines: [['product' => $this->product, 'quantity' => '2']],
        createdBy: $this->owner,
    );
    $owner = $this->actingAs($this->owner);
    $owner->post("/sales/deliveries/{$delivery->id}/consignment", [
        'money_account_id' => $courier->id, 'consignment_number' => 'DB241026ABCD', 'cod_amount' => '1700',
    ])->assertSessionHasNoErrors();
    $consignment = Consignment::query()->sole();

    $owner->post("/sales/consignments/{$consignment->id}/outcome", [
        'outcome' => 'returned', 'outcome_on' => '2026-10-24', 'collected_amount' => '0',
        'lines' => [['delivery_line_id' => $delivery->lines->first()->id, 'damaged' => '1']],
    ])->assertSessionHasNoErrors();

    $line = SalesReturn::query()->sole()->lines->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Returned)
        ->and((float) $line->quantity_to_stock)->toBe(1.0)
        ->and((float) $line->quantity_to_quarantine)->toBe(1.0)
        ->and(($this->inWarehouse)($this->main))->toBe(99.0)
        ->and(($this->inWarehouse)($this->quarantine))->toBe(1.0);
});
