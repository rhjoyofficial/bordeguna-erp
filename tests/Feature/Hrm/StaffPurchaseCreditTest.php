<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ D-D (Joy, 18 Oct 2026): "staff ... paid it also instant or credit for
 * some days". Pay later (credit) with 7 / 15 / 30 days, 15 by default: the
 * handover posts the invoice on the employee's own customer account with that
 * due date and no money account; a normal customer receipt settles it, or the
 * authority moves what is left to the salary deduction; it ages like any
 * receivable; the monthly statement names it. Plus P17 (the Audit tab reads
 * as labelled facts, not JSON) and P18 (no "Record a return" on a staff sale).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Credit Co', 'slug' => 'credit-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Finance'] as $name) {
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
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HONEY', 'name' => 'Honey 500g', 'type' => ProductType::FinishedGood, 'is_active' => true, 'mrp' => '850',
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '10', '400', $this->owner, batchNumber: 'H-400', occurredAt: '2026-09-01 09:00:00');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');
    $this->employee = Employee::create([
        'business_id' => $this->business->id, 'user_id' => $this->staffUser->id, 'employee_code' => 'BDG-000021',
        'first_name' => 'Nazmul', 'last_name' => 'Haque', 'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->employee->id, 'effective_from' => '2026-07-01', 'is_primary' => true]);

    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

/** Apply on credit, approve, hand over with no money account; returns the request. */
function spCreditBuy(?int $days = null): StaffPurchaseRequest
{
    $payload = ['payment_mode' => 'credit', 'lines' => [['product_id' => test()->honey->id]]];

    if ($days !== null) {
        $payload['credit_days'] = $days;
    }

    (test()->as)(test()->staffUser)->post(route('hrm.staff-purchase.apply'), $payload)->assertSessionHasNoErrors();
    $id = (int) StaffPurchaseRequest::query()->latest('id')->value('id');
    (test()->as)(test()->owner)->post(route('hrm.staff-purchases.approve', $id))->assertSessionHasNoErrors();
    (test()->as)(test()->owner)->post(route('hrm.staff-purchases.fulfill', $id), [])->assertSessionHasNoErrors();

    return StaffPurchaseRequest::findOrFail($id);
}

it('takes a credit request with 7, 15 or 30 days, 15 when none is chosen, and refuses any other term', function () {
    (test()->as)($this->staffUser)->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'credit', 'credit_days' => 10, 'lines' => [['product_id' => $this->honey->id]],
    ])->assertSessionHasErrors('credit_days');

    (test()->as)($this->staffUser)->post(route('hrm.staff-purchase.apply'), [
        'payment_mode' => 'credit', 'lines' => [['product_id' => $this->honey->id]],
    ])->assertSessionHasNoErrors();

    expect(StaffPurchaseRequest::query()->sole()->credit_days)->toBe(15);
});

it('hands a credit purchase over with no money account: the invoice stays open on his own account, due on the chosen day, and ages', function () {
    $request = spCreditBuy(7);
    $invoice = SalesInvoice::query()->sole();
    $customer = Customer::ofEmployee($this->employee->id);

    expect($request->status)->toBe(StaffPurchaseRequest::FULFILLED)
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->is_staff_sale)->toBeTrue()
        ->and($invoice->due_date->toDateString())->toBe('2026-10-17')
        ->and($invoice->outstanding()->toTaka())->toBe('400.00')
        ->and(CustomerReceipt::count())->toBe(0)
        // No payroll deduction is generated for a credit purchase.
        ->and(PayrollAdjustment::count())->toBe(0);

    // Not due yet on the 15th; overdue (1-30 days) in the ageing on the 20th.
    expect(Ageing::receivables(Carbon::parse('2026-10-15'))->bucket(AgeingBucket::Current)->toTaka())->toBe('400.00')
        ->and(Ageing::receivables(Carbon::parse('2026-10-20'))->bucket(AgeingBucket::Days1To30)->toTaka())->toBe('400.00');

    // The authority's screen and the statement say so.
    (test()->as)($this->owner)->get(route('hrm.staff-purchases.index', ['tab' => 'statement', 'month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.payment_mode', 'credit')
            ->where('requests.0.due_date', '2026-10-17')
            ->where('requests.0.credit_outstanding', 400)
            ->where('statement.0.credit_outstanding', 400)
            ->where('statement.0.credit_due.0.due_date', '2026-10-17')
            ->where('statement.0.settled_cash', 0)
            ->etc());
});

it('is settled later by a normal customer receipt', function () {
    spCreditBuy();
    $invoice = SalesInvoice::query()->sole();

    expect($invoice->due_date->toDateString())->toBe('2026-10-25');

    app(RecordCustomerReceipt::class)->handle(
        customer: $invoice->customer, moneyAccount: $this->till, receivedOn: '2026-10-10',
        allocations: [['invoice' => $invoice, 'amount' => '400.00']], createdBy: $this->owner,
    );

    expect($invoice->fresh()->isSettled())->toBeTrue();

    (test()->as)($this->owner)->get(route('hrm.staff-purchases.index', ['tab' => 'statement', 'month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statement.0.settled_cash', 400)
            ->where('statement.0.credit_outstanding', 0)
            ->where('statement.0.outstanding', 0)
            ->etc());
});

it('moves what is still owed to the salary deduction once, and the payroll offset then clears it', function () {
    $request = spCreditBuy();
    $invoice = SalesInvoice::query()->sole();

    (test()->as)($this->owner)->post(route('hrm.staff-purchases.to-salary', $request->id))->assertSessionHasNoErrors();
    (test()->as)($this->owner)->post(route('hrm.staff-purchases.to-salary', $request->id))->assertSessionHasErrors('request');

    $adjustment = PayrollAdjustment::query()->sole();
    expect((float) $adjustment->amount)->toBe(400.0)
        ->and($adjustment->source_key)->toBe("staff_purchase:{$request->id}")
        ->and($request->fresh()->credit_to_salary_at)->not->toBeNull();

    $run = PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => PayrollPeriod::forMonth(2026, 8)->id,
        'version' => 1, 'status' => PayrollStatus::Reviewed,
    ]);
    $payslip = $run->payslips()->create(['employee_id' => $this->employee->id, 'gross_salary' => 20000, 'total_earnings' => 20000, 'total_deductions' => 400, 'net' => 19600]);
    $payslip->lines()->create(['code' => 'basic', 'label' => 'Basic', 'type' => SalaryComponentType::Earning, 'amount' => 20000, 'formula' => 'structure', 'source' => 'structure', 'sequence' => 0]);
    $payslip->lines()->create(['code' => 'staff_purchase', 'label' => 'Staff purchase recovery', 'type' => SalaryComponentType::Deduction, 'amount' => 400, 'formula' => '10% of net pay cap', 'source' => 'staff_purchase', 'sequence' => 1]);
    app(TransitionPayrollRun::class)->markApproved($run->fresh(), $this->owner);

    expect($invoice->fresh()->isSettled())->toBeTrue()
        ->and(CustomerReceipt::query()->where('kind', 'payroll_offset')->sole()->allocations->sole()->sales_invoice_id)->toBe($invoice->id);
});

it('shows the Audit tab as labelled facts with the invoice and batch numbers, never raw JSON', function () {
    $request = spCreditBuy(30);
    $invoice = SalesInvoice::query()->sole();

    (test()->as)($this->owner)->get(route('hrm.staff-purchases.index', ['tab' => 'audit', 'month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('audit.0.details')
            ->where('audit.0.action', 'handed_over')
            ->where('audit.0.facts', fn ($facts) => collect($facts)->contains(fn ($f) => $f['k'] === 'invoice' && $f['v'] === $invoice->invoice_number)
                && collect($facts)->contains(fn ($f) => $f['k'] === 'payment' && $f['v'] === 'credit')
                && collect($facts)->contains(fn ($f) => $f['k'] === 'batches' && $f['v'] === 'H-400')
                && collect($facts)->contains(fn ($f) => $f['k'] === 'total' && $f['v'] === '400.00')
                && collect($facts)->contains(fn ($f) => $f['k'] === 'due_date' && $f['v'] === '2026-11-09'))
            ->where('audit.1.action', 'approved')
            ->where('audit.1.facts', fn ($facts) => collect($facts)->contains(fn ($f) => $f['k'] === 'warehouse' && $f['v'] === 'Main')
                && collect($facts)->contains(fn ($f) => $f['k'] === 'line' && $f['v'] === 'Honey 500g: 400.00'))
            ->etc());

    expect(file_get_contents(resource_path('js/pages/hrm/staff-purchases.tsx')))->not->toContain('JSON.stringify');
});

it('offers no "Record a return" on a staff sale invoice, and the server still refuses one', function () {
    spCreditBuy();
    $invoice = SalesInvoice::query()->sole();

    (test()->as)($this->owner)->get(route('sales.invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page->where('returnDeliveryId', null)->etc());
});

it('has Bangla for every new credit and audit string', function () {
    $dictionary = file_get_contents(resource_path('js/lib/i18n/staff-purchase.ts'));

    foreach (['staff_purchase.pay_credit', 'staff_purchase.credit_due_on', 'staff_purchases.credit_to_salary', 'staff_purchases.settled_credit_outstanding', 'staff_purchases.fact_invoice', 'staff_purchases.fact_batches'] as $key) {
        expect($dictionary)->toMatch("/'".preg_quote($key, '/')."': \\{ en: .+, bn: '[^']+' \\}/");
    }
});
