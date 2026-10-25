<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\StaffPurchaseLine;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Hrm\Support\StaffPurchaseLimits;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's answer 6, first part (25 Oct 2026): a not-for-sale item is refused on
 * every sales path, BUT the Owner or a Manager may sell one to an employee
 * through Staff purchases at a typed price with a reason (kept in History),
 * then approved and handed over as any staff purchase.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Seed Co', 'slug' => 'seed-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new PayrollAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-10-10 11:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

    $this->seed = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id,
        'sku' => 'MSEED', 'name' => 'Mustard seed', 'type' => ProductType::RawMaterial, 'is_active' => true, 'can_be_sold' => false,
    ]);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id,
        'sku' => 'HONEY', 'name' => 'Honey', 'type' => ProductType::FinishedGood, 'is_active' => true, 'mrp' => '850',
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    app(RecordStockIn::class)->handle($this->seed, $this->warehouse, '10', '100', $this->owner, batchNumber: 'MS-100', occurredAt: '2026-09-01 09:00:00');
    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '10', '400', $this->owner, batchNumber: 'H-400', occurredAt: '2026-09-01 09:00:00');

    $staffUser = User::factory()->create();
    $staffUser->businesses()->attach($this->business->id);
    $staffUser->assignRole('Staff');
    $this->employee = Employee::create([
        'business_id' => $this->business->id, 'user_id' => $staffUser->id, 'employee_code' => 'BDG-000031',
        'first_name' => 'Rana', 'last_name' => 'Hossain', 'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->employee->id, 'effective_from' => '2026-07-01', 'is_primary' => true]);

    $this->till = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->payload = fn (array $extra = []) => $extra + [
        'employee_id' => $this->employee->id, 'product_id' => $this->seed->id, 'quantity' => '2',
        'unit_price' => '150', 'reason' => 'Rana takes seed for his home garden', 'payment_mode' => 'cash',
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('lets the Owner sell a not-for-sale item to an employee at a typed price, kept in History, then approved and handed over', function () {
    ($this->as)($this->owner)->get(route('hrm.staff-purchases.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canSellCustom', true)->where('customChoices.products.0.label', 'Mustard seed (MSEED)')->where('customChoices.products.0.cost', '100.00')->etc());

    ($this->as)($this->owner)->post(route('hrm.staff-purchases.custom'), ($this->payload)())->assertSessionHasNoErrors();

    $row = StaffPurchaseRequest::query()->sole();
    $line = $row->lines->sole();

    expect($line->priced_at)->toBe(StaffPurchaseLine::PRICED_AT_CUSTOM)
        ->and((float) $line->staff_price)->toBe(150.0)
        ->and($line->custom_reason)->toBe('Rana takes seed for his home garden')
        ->and((int) $line->custom_by)->toBe($this->owner->id)
        ->and($row->events()->where('action', 'custom_price')->exists())->toBeTrue()
        // Outside the staff benefit's monthly allowance.
        ->and(StaffPurchaseLimits::referenceUsed($this->employee->id, Carbon::now()))->toBe(0.0);

    ($this->as)($this->owner)->post(route('hrm.staff-purchases.approve', $row->id))->assertSessionHasNoErrors();
    expect((float) $line->fresh()->staff_price)->toBe(150.0);

    ($this->as)($this->owner)->post(route('hrm.staff-purchases.fulfill', $row->id), ['money_account_id' => $this->till->id])->assertSessionHasNoErrors();

    $row->refresh();
    $invoice = SalesInvoice::query()->findOrFail($row->sales_invoice_id);
    $invoiceLine = $invoice->lines()->sole();

    expect($row->status)->toBe(StaffPurchaseRequest::FULFILLED)
        ->and((float) $invoice->total)->toBe(300.0)
        ->and((float) $invoiceLine->unit_price)->toBe(150.0)
        ->and((bool) $invoiceLine->is_staff_sale)->toBeTrue()
        ->and((float) StockBatch::query()->where('batch_number', 'MS-100')->value('current_quantity'))->toBe(8.0)
        ->and((int) JournalLine::where('account_id', $this->till->account_id)->sum('amount_minor'))->toBe(30000)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('posts the cost of a not-for-sale staff sale at the exact value the stock moved (exact batch value)', function () {
    // 3 kg worth exactly Tk 100.00 (an older lot, taken first): a unit cost
    // of 33.3333 a kg. The 2 kg leave at their share of the exact value.
    $lot = app(RecordStockIn::class)->handle($this->seed, $this->warehouse, '3', '33.3333', $this->owner, batchNumber: 'MS-ODD', occurredAt: '2026-08-01 09:00:00', valueMinor: 10_000);

    ($this->as)($this->owner)->post(route('hrm.staff-purchases.custom'), ($this->payload)())->assertSessionHasNoErrors();
    $row = StaffPurchaseRequest::query()->sole();
    ($this->as)($this->owner)->post(route('hrm.staff-purchases.approve', $row->id))->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post(route('hrm.staff-purchases.fulfill', $row->id), ['money_account_id' => $this->till->id])->assertSessionHasNoErrors();

    $moved = StockMovement::query()->where('batch_id', $lot->batch_id)->where('direction', MovementDirection::Out)->sole();
    $cogs = (int) JournalLine::where('account_id', Account::bySlug('cost_of_goods_sold')->id)->sum('amount_minor');

    // 10,000 x 2 / 3 = 6,666.67 -> 6,667 paisa; the batch keeps exactly 3,333.
    expect((int) $moved->value_minor)->toBe(6_667)
        ->and($cogs)->toBe(6_667)
        ->and((int) StockBatch::query()->whereKey($lot->batch_id)->value('current_value'))->toBe(3_333)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('still refuses the not-for-sale item at the counter, and a staff sale with no custom line for it', function () {
    $counter = fn (array $extra = []) => app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->seed, 'quantity' => '1', 'unitPrice' => '150'] + $extra],
        createdBy: $this->owner,
        moneyAccount: $this->till,
    );

    expect(fn () => $counter())->toThrow(ValidationException::class, 'is not for sale');

    // A staff request priced the ordinary way gives no pass for the seed.
    $other = StaffPurchaseRequest::create([
        'business_id' => $this->business->id, 'number' => 'SP-X', 'employee_id' => $this->employee->id,
        'warehouse_id' => $this->warehouse->id, 'payment_mode' => 'cash', 'status' => StaffPurchaseRequest::APPROVED,
    ]);
    $other->lines()->create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'quantity' => '1', 'staff_price' => '400', 'mrp' => '850']);

    expect(fn () => $counter(['isStaffSale' => true, 'reservedFor' => $other]))->toThrow(ValidationException::class, 'is not for sale');
});

it('is for the Owner or a Manager only, for a not-for-sale item only, and needs a reason and a price', function () {
    $hr = User::factory()->create();
    $hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    Role::create(['name' => 'HR', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('hrm.employees.manage');
    $hr->assignRole('HR');

    ($this->as)($hr)->post(route('hrm.staff-purchases.custom'), ($this->payload)())->assertForbidden();

    ($this->as)($this->owner)->post(route('hrm.staff-purchases.custom'), ($this->payload)(['product_id' => $this->honey->id]))->assertSessionHasErrors('product_id');
    ($this->as)($this->owner)->post(route('hrm.staff-purchases.custom'), ($this->payload)(['reason' => 'x']))->assertSessionHasErrors('reason');
    ($this->as)($this->owner)->post(route('hrm.staff-purchases.custom'), ($this->payload)(['unit_price' => '0']))->assertSessionHasErrors('unit_price');

    expect(StaffPurchaseRequest::count())->toBe(0);
});
