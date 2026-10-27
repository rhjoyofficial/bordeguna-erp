<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\StaffPurchaseLimits;
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

// ⭐ Phase 2 (17 Oct 2026, ChatGPT ruling Q1): the staff price is the COST of
// the FIFO batch (honey came in at 400, shutki at 1,500), shown as an estimate
// until approval freezes it. The hand-typed STAFF row (600) decides nothing.
it('shows staff only benefit products at the estimated batch cost, with the month allowance', function () {
    ($this->asStaff)()->get(route('hrm.staff-purchase'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/staff-purchase')
            // Ghee has no stock to issue: it simply is not on the list.
            ->has('products', 2)
            ->where('products.0.staff_price', fn ($value) => (float) $value === 400.0)
            ->where('products.0.is_estimate', true)
            ->where('allowance.limit', fn ($value) => (float) $value === 3000.0)
            ->where('allowance.used', fn ($value) => (float) $value === 0.0));
});

it('applies within the caps, and the caps refuse at the reference price - not at staff price', function () {
    applyFor([['product_id' => $this->honey->id]]);

    $request = DB::table('staff_purchase_requests')->first();
    expect($request->number)->toStartWith('SPB-')
        ->and($request->status)->toBe('pending');

    $line = DB::table('staff_purchase_lines')->first();
    expect((float) $line->staff_price)->toBe(400.0)
        ->and((float) $line->mrp)->toBe(850.0)
        ->and((float) $line->cap_reference_price)->toBe(850.0)
        ->and($line->priced_at)->toBe('staff');

    // Same SKU again this month: one unit per SKU per month at the staff
    // price, so the second unit is priced at the reference price (ruling Q3).
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'cash',
        'lines' => [['product_id' => $this->honey->id]],
    ])->assertSessionHasNoErrors();

    $second = DB::table('staff_purchase_lines')->orderByDesc('id')->first();
    expect($second->priced_at)->toBe('reference')
        ->and((float) $second->staff_price)->toBe(850.0);

    // Shutki 2,500 + honey 850 + 850 already used = 4,200 > 3,000: refused
    // even though the batch costs would fit - the cap counts the reference price.
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'cash',
        'lines' => [['product_id' => $this->shutki->id]],
    ])->assertSessionHasErrors('lines');

    expect(DB::table('staff_purchase_requests')->count())->toBe(2);
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

    // Phase 2: sold at the reserved batch cost (400), to the employee's own
    // customer row, flagged as a staff sale.
    expect($request->status)->toBe('fulfilled')
        ->and($request->sales_invoice_id)->toBe($invoice->id)
        ->and((float) $invoice->total)->toBe(400.0)
        ->and((bool) $invoice->is_staff_sale)->toBeTrue()
        ->and($invoice->customer_id)->toBe(DB::table('customers')->where('employee_id', $this->staffEmployee->id)->value('id'))
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
        ->and((float) $adjustment->amount)->toBe(400.0)
        ->and($adjustment->source_key)->toContain('staff_purchase')
        // On account: the invoice stands open until payroll settles it.
        ->and(DB::table('sales_invoices')->count())->toBe(1);
});

// ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23".
it('counts the one-unit rule and the allowance over the 24-23 cycle, so the 24th starts afresh', function () {
    Carbon::setTestNow('2026-09-23 11:00:00');
    applyFor([['product_id' => $this->honey->id]]);

    // 24 Sep is still September on the calendar, but it opens the October cycle.
    Carbon::setTestNow('2026-09-24 09:00:00');
    applyFor([['product_id' => $this->honey->id]]);

    // 30 Sep is the same cycle as 24 Sep: the second honey is at the reference price.
    Carbon::setTestNow('2026-09-30 09:00:00');
    applyFor([['product_id' => $this->honey->id]]);

    expect(DB::table('staff_purchase_lines')->orderBy('id')->pluck('priced_at')->all())->toBe(['staff', 'staff', 'reference'])
        ->and(StaffPurchaseLimits::referenceUsed($this->staffEmployee->id, Carbon::parse('2026-10-23')))->toBe(1700.0)
        ->and(StaffPurchaseLimits::referenceUsed($this->staffEmployee->id, Carbon::parse('2026-09-23')))->toBe(850.0);

    // HR's statement and audit tabs open on the cycle containing today, by its label.
    ($this->asHr)()->get(route('hrm.staff-purchases.index', ['tab' => 'audit']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('month', '2026-10')
            ->where('month_range', 'October 2026 (24 Sep to 23 Oct 2026)'));
});

it('keeps approval away from the staff themselves', function () {
    $id = applyFor([['product_id' => $this->honey->id]]);

    ($this->asStaff)()->post(route('hrm.staff-purchases.approve', $id))
        ->assertForbidden();
});

it('puts a deduction handed over after the cut-off into the next payroll month', function () {
    // ⚠⚠ Found 3 Oct 2026: the period was the CALENDAR month, so a hand-over on
    // 26 September landed in the September period (24 Aug - 23 Sep), which had
    // already ended. On the 24-23 cycle 26 September belongs to October.
    Carbon::setTestNow('2026-09-26 11:00:00');

    $id = applyFor([['product_id' => $this->honey->id]], 'salary_deduction');
    ($this->asHr)()->post(route('hrm.staff-purchases.approve', $id));
    ($this->asHr)()->post(route('hrm.staff-purchases.fulfill', $id), [])
        ->assertSessionHasNoErrors();

    $period = PayrollPeriod::find(PayrollAdjustment::query()->sole()->payroll_period_id);

    expect($period->month)->toBe(10)
        ->and($period->year)->toBe(2026);
});

/*
 * HRM-003 (audit 14 Oct 2026). Hand over read the status outside the
 * transaction with no lock, so a double press (or two clerks) both saw
 * "approved" and made two counter sales: stock out twice, money in twice.
 * The race is staged here by letting a "second worker" finish the hand-over
 * between this request's status check and its transaction: the moment the
 * request reads its lines, the row turns fulfilled underneath it.
 */
it('refuses a second hand-over that raced the first, and makes only one sale', function () {
    $id = applyFor([['product_id' => $this->honey->id]]);
    ($this->asHr)()->post(route('hrm.staff-purchases.approve', $id))->assertSessionHasNoErrors();

    $flipped = false;
    DB::listen(function ($query) use ($id, &$flipped) {
        if (! $flipped && str_contains($query->sql, 'staff_purchase_lines')) {
            $flipped = true;
            DB::table('staff_purchase_requests')->where('id', $id)->update(['status' => 'fulfilled']);
        }
    });

    ($this->asHr)()->post(route('hrm.staff-purchases.fulfill', $id), [
        'money_account_id' => $this->till->id,
    ])->assertSessionHasErrors('request');

    expect(DB::table('sales_invoices')->count())->toBe(0)
        ->and($this->honey->fresh()->currentStockQuantity())->toBe('20');
});

/*
 * HRM-006 (audit 14 Oct 2026). The "Staff purchases" link sat under the payroll
 * permissions while the screen authorizes hrm.employees.manage, so a role with
 * the employee permission had a screen and no way in, and a payroll-only role
 * had a link that answered 403. The link now follows the server's permission.
 */
it('links Staff purchases under the same permission the screen checks', function () {
    // Since 15 Oct 2026 the HRM sidebar rows and tab rows are built from
    // HRM_SECTIONS, so the gate is read there (and the old sidebar has no copy).
    $sections = file_get_contents(resource_path('js/components/hrm/hrm-sections.ts'));

    expect($sections)->toMatch("/url: '\/hrm\/staff-purchases', can: \(f\) => f\.canManageEmployees/")
        ->and(substr_count($sections, "'/hrm/staff-purchases'"))->toBe(1)
        ->and(file_get_contents(resource_path('js/components/app-sidebar.tsx')))->not->toContain("'/hrm/staff-purchases'");

    // A role holding only the employee permission: the link's prop is on and
    // the screen opens.
    $role = Role::create(['name' => 'Store HR', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    $role->givePermissionTo('hrm.employees.manage');
    $storeHr = User::factory()->create();
    $storeHr->businesses()->attach($this->business->id);
    $storeHr->assignRole('Store HR');

    $this->actingAs($storeHr)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.staff-purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageEmployees', true));
});

// ⚠ Bug hunt 16 Oct 2026 (02 L15): one request naming a product twice got two
// units at the staff price; the one-per-month check reads saved requests only.
it('refuses one request that names the same product twice', function () {
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'cash',
        'lines' => [['product_id' => $this->honey->id], ['product_id' => $this->honey->id]],
    ])->assertSessionHasErrors('lines.1.product_id');

    expect(DB::table('staff_purchase_requests')->count())->toBe(0);
});

// ⚠ Bug hunt 16 Oct 2026 (02 L16): per row, the employee twice, the lines, each
// product and a sum: 400 queries and more for the HR list.
it('lists the requests for HR in the same number of queries however many there are', function () {
    $request = function (int $n) {
        $id = DB::table('staff_purchase_requests')->insertGetId([
            'business_id' => $this->business->id, 'number' => "SPB-TEST-{$n}", 'employee_id' => $this->staffEmployee->id,
            'payment_mode' => 'cash', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('staff_purchase_lines')->insert([
            'business_id' => $this->business->id, 'request_id' => $id, 'product_id' => $this->honey->id,
            'staff_price' => 600, 'mrp' => 850, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $list = fn () => ($this->asHr)()->get(route('hrm.staff-purchases.index'))->assertOk()->original->getData()['page']['props']['requests'];
    $count = function () use ($list) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $list();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $rows];
    };

    $request(1);
    $list();
    [$few] = $count();
    foreach (range(2, 6) as $n) {
        $request($n);
    }
    [$many, $rows] = $count();

    expect($many)->toBe($few)
        ->and($rows)->toHaveCount(6)
        ->and($rows[0]['employee'])->toBe('Munna Islam')
        ->and($rows[0]['lines'][0]['product'])->toBe($this->honey->name)
        ->and($rows[0]['total_staff_price'])->toBe(600.0);
});
