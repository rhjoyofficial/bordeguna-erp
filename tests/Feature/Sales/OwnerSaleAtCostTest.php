<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reporting\SalesFacts;
use App\Modules\Sales\Support\SalesTargetProgress;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ D-B (Joy, 18 Oct 2026, "Cost price"): the proprietor (Moin) buys as a
 * customer of his own, at the cost of the FIFO batch the sale consumes in the
 * chosen warehouse. No MRP fallback, no monthly cap, no one-unit rule, normal
 * receivable, no equity entry. Red -> green:
 *
 *  - the account is made from Settings > Company, once, type owner, OWNER group;
 *  - the engine answers with the reserved/FIFO head batch cost of THAT warehouse
 *    (not the newest, not another warehouse's), weighted over the slices;
 *  - the counter sells at that cost with list price = MRP and the trail kept,
 *    for any quantity (no cap), and a cashier's discount cap is not tripped;
 *  - on credit the invoice stays open in AR and ages; a receipt clears it;
 *  - an order is priced the same way;
 *  - owner sales leave the retail KPIs and stay in the ledger;
 *  - the cashier sees the price, not that it is the batch cost.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Owner Co', 'slug' => 'owner-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Finance'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    (new FoundationPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new PriceGroupSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-10-18 11:00:00');

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $this->savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    // Pink salt: printed MRP 890, a retail price row 890 too.
    $this->salt = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SALT', 'name' => 'Pink Salt 1KG', 'type' => ProductType::FinishedGood, 'is_active' => true, 'mrp' => '890',
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->salt->id, 'unit_price' => '890', 'effective_from' => '2026-01-01']);

    $this->moin = ownerSaleUser('Owner', 'Chowdhury Mohammad Moin');
    $this->joy = ownerSaleUser('Owner', 'Joy');
    $this->cashier = ownerSaleUser('Staff', 'Rahim');
    $this->business->update(['proprietor_user_id' => $this->moin->id]);

    // FIFO in Main: 566.3135 (older, 2 units) before 600 (newer, 10 units).
    // Savar has its own batch at 700.
    $in = app(RecordStockIn::class);
    $in->handle($this->salt, $this->main, '2', '566.3135', $this->moin, batchNumber: 'PRN-1', occurredAt: '2026-10-01 09:00:00');
    $in->handle($this->salt, $this->main, '10', '600', $this->moin, batchNumber: 'PRN-2', occurredAt: '2026-10-05 09:00:00');
    $in->handle($this->salt, $this->savar, '5', '700', $this->moin, batchNumber: 'SAV-1', occurredAt: '2026-10-02 09:00:00');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC', 'type' => 'bank',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->ownerCustomer = fn () => Customer::forProprietor($this->moin, $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function ownerSaleUser(string $role, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->businesses()->attach(test()->business->id);
    setPermissionsTeamId(test()->business->id);
    $user->assignRole($role);

    return $user;
}

it('seeds the OWNER group with a cost basis and never touches the other groups', function () {
    $owner = PriceGroup::query()->where('code', 'OWNER')->sole();
    $retail = PriceGroup::query()->where('code', 'RETAIL')->sole();

    expect($owner->price_basis)->toBe(PriceGroup::BASIS_COST)
        ->and($owner->falls_back_to_mrp)->toBeFalse()
        ->and($retail->price_basis)->toBe(PriceGroup::BASIS_LIST);

    // A hand-made OWNER group elsewhere is never adopted by the seeder.
    PriceGroup::query()->whereKey($owner->id)->update(['price_basis' => 'list', 'name' => 'Owner (typed)']);
    (new PriceGroupSeeder)->run();
    TenantScope::enter($this->business->id);

    expect(PriceGroup::query()->where('code', 'OWNER')->count())->toBe(1)
        ->and(PriceGroup::query()->where('code', 'OWNER')->value('price_basis'))->toBe('list');
});

it('creates the proprietor\'s customer account from Settings once, type owner in the OWNER group, linked to his employee record', function () {
    Employee::create([
        'business_id' => $this->business->id, 'user_id' => $this->moin->id, 'employee_code' => 'BDG-000001',
        'first_name' => 'Chowdhury Mohammad', 'last_name' => 'Moin', 'employment_type' => 'permanent', 'status' => 'active',
    ]);

    ($this->as)($this->joy)->get('/settings/business')
        ->assertInertia(fn (Assert $page) => $page->where('owner_customer', null)->where('proprietor_name', 'Chowdhury Mohammad Moin')->etc());

    ($this->as)($this->joy)->post(route('sales.owner-customer.store'))->assertSessionHas('success');
    ($this->as)($this->joy)->post(route('sales.owner-customer.store'))->assertSessionHas('success');

    $customer = Customer::query()->where('type', CustomerType::Owner->value)->sole();

    expect($customer->owner_user_id)->toBe($this->moin->id)
        ->and($customer->employee_id)->toBe(Employee::query()->value('id'))
        ->and($customer->priceGroup->code)->toBe('OWNER')
        ->and($customer->name)->toBe('Chowdhury Mohammad Moin');

    ($this->as)($this->joy)->get('/settings/business')
        ->assertInertia(fn (Assert $page) => $page->where('owner_customer.code', $customer->code)->etc());

    // A cashier cannot make it.
    ($this->as)($this->cashier)->post(route('sales.owner-customer.store'))->assertForbidden();
});

it('prices an owner line at the FIFO head batch cost of the chosen warehouse: no MRP, no newest batch, weighted over the slices', function () {
    $customer = ($this->ownerCustomer)();
    $engine = app(ResolveSellingPrice::class);

    $one = $engine->resolve($this->salt, $customer, '1', warehouseId: $this->main->id);
    expect($one->unitPrice)->toBe('566.3135')
        ->and($one->isBatchCost())->toBeTrue()
        ->and($one->explain())->toBe('Owner price: batch cost (PRN-1)');

    // Three units: 2 at 566.3135 + 1 at 600 = 1,732.627 / 3.
    expect($engine->resolve($this->salt, $customer, '3', warehouseId: $this->main->id)->unitPrice)->toBe('577.5423');

    // Another warehouse answers with its own batch.
    expect($engine->resolve($this->salt, $customer, '1', warehouseId: $this->savar->id)->unitPrice)->toBe('700.0000');

    // What an approved document holds is skipped, exactly as the issue skips it.
    app(ReserveStock::class)->handle($this->salt, $this->main, '2', 'test', $this->business, $this->moin);
    expect($engine->resolve($this->salt, $customer, '1', warehouseId: $this->main->id)->unitPrice)->toBe('600.0000');

    // Nothing to issue: no price at all, never the MRP.
    $empty = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Empty', 'code' => 'EMP']);
    expect($engine->find($this->salt, $customer, '1', warehouseId: $empty->id))->toBeNull();

    // A walk-in still pays the list price.
    expect($engine->resolve($this->salt, Customer::walkIn(), '1')->unitPrice)->toBe('890.0000');
});

it('sells to him at the counter at batch cost for any quantity, list price MRP kept with the trail, on account in AR until a receipt clears it', function () {
    $customer = ($this->ownerCustomer)();

    // Eight units by a Staff cashier on account: no monthly cap, no one-unit
    // rule, and the cashier's 1% / Tk 300 discount cap is not tripped.
    ($this->as)($this->cashier)->post(route('sales.counter.store'), [
        'customer_id' => $customer->id,
        'warehouse_id' => $this->main->id,
        'lines' => [['product_id' => $this->salt->id, 'quantity' => '8']],
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->where('customer_id', $customer->id)->sole();
    $line = $invoice->lines->sole();

    // 2 x 566.3135 + 6 x 600 = 4,732.627 -> 591.5783 a unit.
    expect((string) $line->unit_price)->toBe('591.5783')
        ->and((string) $line->list_price)->toBe('890.0000')
        ->and($line->price_overridden)->toBeTrue()
        ->and($line->override_reason)->toStartWith('Owner price: batch cost')
        ->and($line->is_owner_sale)->toBeTrue()
        ->and($invoice->is_owner_sale)->toBeTrue()
        ->and($invoice->is_staff_sale)->toBeFalse()
        // Revenue equals the cost released: zero margin by design.
        ->and(abs((float) $line->line_net - (float) $line->cost_released))->toBeLessThan(0.02)
        ->and($invoice->outstanding()->toTaka())->toBe(number_format((float) $invoice->total, 2, '.', ''));

    // No equity entry: only revenue, AR, COGS and stock moved.
    expect(JournalLine::query()->where('account_id', Account::bySlug('owners_drawings')?->id ?? 0)->count())->toBe(0);

    // In the receivables ageing like any customer, due after his credit days.
    $ageing = Ageing::receivables(Carbon::parse('2026-11-30'));
    expect($ageing->rows->firstWhere('partyId', $customer->id)?->total()->toTaka())->toBe(number_format((float) $invoice->total, 2, '.', ''));

    // He pays later: a normal customer receipt clears it.
    app(RecordCustomerReceipt::class)->handle(
        customer: $customer, moneyAccount: $this->bank, receivedOn: '2026-10-18',
        allocations: [['invoice' => $invoice, 'amount' => (string) $invoice->total]], createdBy: $this->joy,
    );

    expect($invoice->fresh()->isSettled())->toBeTrue();
});

it('pays now at the counter too, at the batch cost', function () {
    $sale = app(RecordCounterSale::class)->handle(
        customer: ($this->ownerCustomer)(), warehouse: $this->main,
        lines: [['product' => $this->salt, 'quantity' => '1']], createdBy: $this->joy, moneyAccount: $this->bank,
    );

    expect((string) $sale->invoice->lines->sole()->unit_price)->toBe('566.3135')
        ->and($sale->invoice->isSettled())->toBeTrue();
});

it('prices a sales order for him at the batch cost of the order\'s warehouse', function () {
    $order = app(CreateSalesOrder::class)->handle(
        customer: ($this->ownerCustomer)(),
        lines: [['product' => $this->salt, 'quantity' => '1']],
        createdBy: $this->joy,
        warehouse: $this->savar,
    );

    $line = $order->lines->sole();
    expect((string) $line->unit_price)->toBe('700.0000')
        ->and($line->price_note)->toBe('Owner price: batch cost (SAV-1)');

    // The order form's price check answers the same, with the warehouse.
    ($this->as)($this->joy)->getJson(route('sales.orders.price', ['product_id' => $this->salt->id, 'quantity' => 1, 'customer_id' => $order->customer_id, 'warehouse_id' => $this->savar->id]))
        ->assertOk()->assertJsonPath('unit_price', '700.0000')->assertJsonPath('explanation', 'Owner price: batch cost (SAV-1)');
});

it('leaves owner sales out of the retail KPIs and keeps them in the ledger', function () {
    app(RecordCounterSale::class)->handle(
        customer: ($this->ownerCustomer)(), warehouse: $this->main,
        lines: [['product' => $this->salt, 'quantity' => '1']], createdBy: $this->joy, moneyAccount: $this->bank,
    );
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->main,
        lines: [['product' => $this->salt, 'quantity' => '1']], createdBy: $this->joy, moneyAccount: $this->bank,
    );

    $facts = new SalesFacts($this->business->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect(SalesInvoice::count())->toBe(2)
        ->and(SalesInvoice::query()->retail()->count())->toBe(1)
        ->and($facts->invoiceLines()->count())->toBe(1)
        ->and(SalesTargetProgress::actualFor($this->business->id, $this->joy->id, '2026-10-01', '2026-10-31'))->toBe(890.0)
        // Revenue in the ledger: 566.31 + 890.00.
        ->and((int) JournalLine::query()->where('account_id', Account::bySlug('sales_revenue')->id)->sum('amount_minor'))->toBe(-145631);
});

it('shows the cashier the price only: the batch-cost explanation and the reference price are for the Owner and cost readers', function () {
    $customer = ($this->ownerCustomer)();
    $query = ['product_id' => $this->salt->id, 'quantity' => 1, 'customer_id' => $customer->id, 'warehouse_id' => $this->main->id];

    ($this->as)($this->cashier)->getJson(route('sales.counter.price', $query))
        ->assertOk()->assertJsonPath('unit_price', '566.3135')->assertJsonPath('explanation', null);
    ($this->as)($this->joy)->getJson(route('sales.counter.price', $query))
        ->assertOk()->assertJsonPath('explanation', 'Owner price: batch cost (PRN-1)');

    $sale = app(RecordCounterSale::class)->handle(
        customer: $customer, warehouse: $this->main,
        lines: [['product' => $this->salt, 'quantity' => '1']], createdBy: $this->joy, moneyAccount: $this->bank,
    );

    ($this->as)($this->cashier)->get(route('sales.invoices.show', $sale->invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('invoice.lines.0.list_price', null)->where('invoice.lines.0.override_reason', null)->etc());
    ($this->as)($this->joy)->get(route('sales.invoices.show', $sale->invoice))
        ->assertInertia(fn (Assert $page) => $page->where('invoice.lines.0.list_price', '890.0000')
            // ⭐ An owner sale follows the normal returns: the link stays.
            ->where('returnDeliveryId', $sale->delivery->id)->etc());
});
