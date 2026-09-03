<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Staff Product Benefit (office order BDG/HR/09/01/2026, final).
 *
 * The policy, as code: a confidential STAFF price list (staff see only
 * their price); a monthly cap of 3,000 taka COUNTED AT MRP, no rollover;
 * ONE unit per SKU per month at staff price; apply in the ERP, approval by
 * the designated authority, and the store hands over only against an
 * approved application - which becomes a REAL counter sale at the staff
 * price. Salary deduction becomes a generated payroll adjustment: the human
 * decision already happened at approval.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Benefit Co', 'slug' => 'benefit-co']);
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
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-10 11:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $makeProduct = function (string $sku, string $name, string $retail, ?string $staff) use ($unit) {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name, 'type' => ProductType::FinishedGood, 'is_active' => true,
        ]);
        ProductPrice::create([
            'business_id' => $this->business->id, 'product_id' => $product->id,
            'unit_price' => $retail, 'effective_from' => '2026-01-01',
        ]);
        if ($staff !== null) {
            $staffGroup = PriceGroup::firstOrCreate(
                ['business_id' => $this->business->id, 'code' => 'STAFF'],
                ['name' => 'Staff Price', 'is_active' => true],
            );
            ProductPrice::create([
                'business_id' => $this->business->id, 'product_id' => $product->id,
                'unit_price' => $staff, 'price_group_id' => $staffGroup->id, 'effective_from' => '2026-01-01',
            ]);
        }

        return $product;
    };

    // Honey: MRP 850, staff 600. Shutki: MRP 2500, staff 2000.
    // Ghee has NO staff price - not part of the benefit.
    $this->honey = $makeProduct('HONEY', 'Honey 500g', '850', '600');
    $this->shutki = $makeProduct('SHUTKI', 'Premium Shutki', '2500', '2000');
    $this->ghee = $makeProduct('GHEE', 'Ghee 1kg', '1200', null);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '20', '400', $this->hr, occurredAt: '2026-09-01 09:00:00');
    app(RecordStockIn::class)->handle($this->shutki, $this->warehouse, '20', '1500', $this->hr, occurredAt: '2026-09-01 09:00:00');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffEmployee = Employee::create([
        'business_id' => $this->business->id,
        'user_id' => $this->staffUser->id,
        'employee_code' => 'BDG-000012',
        'first_name' => 'Munna', 'last_name' => 'Islam',
        'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->staffEmployee->id,
        'effective_from' => '2026-07-01', 'is_primary' => true,
    ]);

    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->asStaff = fn () => $this->actingAs($this->staffUser)
        ->withSession(['current_business_id' => $this->business->id]);
    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

function applyFor(array $lines, string $mode = 'cash'): int
{
    (test()->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => $mode,
        'lines' => $lines,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('staff_purchase_requests')->latest('id')->value('id');
}

it('shows staff only benefit products at the staff price, with the month allowance', function () {
    ($this->asStaff)()->get(route('hrm.staff-purchase'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/staff-purchase')
            // Ghee has no staff price: it simply is not on the list.
            ->has('products', 2)
            ->where('products.0.staff_price', fn ($value) => (float) $value === 600.0)
            ->where('allowance.limit', fn ($value) => (float) $value === 3000.0)
            ->where('allowance.used', fn ($value) => (float) $value === 0.0));
});

it('applies within the caps, and the caps refuse at MRP - not at staff price', function () {
    applyFor([['product_id' => $this->honey->id]]);

    $request = DB::table('staff_purchase_requests')->first();
    expect($request->number)->toStartWith('SPB-')
        ->and($request->status)->toBe('pending');

    $line = DB::table('staff_purchase_lines')->first();
    expect((float) $line->staff_price)->toBe(600.0)
        ->and((float) $line->mrp)->toBe(850.0);

    // Same SKU again this month: refused - one unit per SKU per month.
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'cash',
        'lines' => [['product_id' => $this->honey->id]],
    ])->assertSessionHasErrors('lines');

    // Shutki MRP 2,500 + honey MRP 850 already used = 3,350 > 3,000: refused
    // even though the STAFF prices would fit - the cap counts MRP.
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'cash',
        'lines' => [['product_id' => $this->shutki->id]],
    ])->assertSessionHasErrors('lines');

    expect(DB::table('staff_purchase_requests')->count())->toBe(1);
});

it('hands over an approved cash request as a real sale at the staff price', function () {
    $id = applyFor([['product_id' => $this->honey->id]]);

    // The store cannot hand over an unapproved application.
    ($this->asHr)()->post(route('hrm.staff-purchases.fulfill', $id), [
        'money_account_id' => $this->till->id,
    ])->assertSessionHasErrors();

    ($this->asHr)()->post(route('hrm.staff-purchases.approve', $id))->assertSessionHasNoErrors();
    ($this->asHr)()->post(route('hrm.staff-purchases.fulfill', $id), [
        'money_account_id' => $this->till->id,
    ])->assertSessionHasNoErrors();

    $request = DB::table('staff_purchase_requests')->find($id);
    $invoice = DB::table('sales_invoices')->first();

    expect($request->status)->toBe('fulfilled')
        ->and($request->sales_invoice_id)->toBe($invoice->id)
        ->and((float) $invoice->total)->toBe(600.0)
        ->and($this->honey->fresh()->currentStockQuantity())->toBe('19');
});

it('turns a salary-deduction handover into a generated payroll adjustment', function () {
    $id = applyFor([['product_id' => $this->honey->id]], 'salary_deduction');

    ($this->asHr)()->post(route('hrm.staff-purchases.approve', $id));
    ($this->asHr)()->post(route('hrm.staff-purchases.fulfill', $id), [])
        ->assertSessionHasNoErrors();

    $adjustment = PayrollAdjustment::query()->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->employee_id)->toBe($this->staffEmployee->id)
        ->and((float) $adjustment->amount)->toBe(600.0)
        ->and($adjustment->source_key)->toContain('staff_purchase')
        // On account: the invoice stands open until payroll settles it.
        ->and(DB::table('sales_invoices')->count())->toBe(1);
});

it('keeps approval away from the staff themselves', function () {
    $id = applyFor([['product_id' => $this->honey->id]]);

    ($this->asStaff)()->post(route('hrm.staff-purchases.approve', $id))
        ->assertForbidden();
});
