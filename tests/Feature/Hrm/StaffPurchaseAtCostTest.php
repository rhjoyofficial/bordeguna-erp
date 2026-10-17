<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
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
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\StaffPurchaseReplacement;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reporting\SalesFacts;
use App\Modules\Sales\Support\ProductPricing;
use App\Modules\Sales\Support\SalesTargetProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff purchase at COST, Phase 2 (ChatGPT ruling 17 Oct 2026, APPROVE WITH
 * CHANGES; decisions D1-D8). Every rule of plan E8, red -> green:
 *
 *  - the staff price = the cost of the FIFO batch RESERVED at approval, frozen
 *    on the line (the head batch, not the newest; no last-cost, no average);
 *  - re-approval when the reserved batch is gone or repriced before handover;
 *  - the Tk 3,000 cap at the reference price, approved reservations counted;
 *  - the month's second unit of a SKU at the reference price;
 *  - a product without MRP measured at its market (retail) price, labelled so;
 *  - the payroll offset clears the invoice: 2130 down, 1140 down, 2140 untouched,
 *    partial with carry-forward, withdrawn when the run is reopened;
 *  - the cost basis is confidential per role;
 *  - staff sales out of the retail KPIs;
 *  - a defect replaced at zero charge, no return path;
 *  - two requests over the cap in the same month: one refused.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cost Co', 'slug' => 'cost-co']);
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

    $this->makeProduct = function (string $sku, string $name, ?string $retail, ?string $mrp = null) use ($unit) {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name, 'type' => ProductType::FinishedGood, 'is_active' => true, 'mrp' => $mrp,
        ]);
        if ($retail !== null) {
            ProductPrice::create([
                'business_id' => $this->business->id, 'product_id' => $product->id,
                'unit_price' => $retail, 'effective_from' => '2026-01-01',
            ]);
        }

        return $product;
    };

    // Honey: printed MRP 850. Shutki: no MRP, market (retail) price 2,500.
    $this->honey = ($this->makeProduct)('HONEY', 'Honey 500g', '850', '850');
    $this->shutki = ($this->makeProduct)('SHUTKI', 'Premium Shutki', '2500', null);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    // Two honey batches at different costs: the FIFO HEAD (400, 1 Sep) is the
    // one reserved and priced, never the newest (450, 5 Sep).
    $stockIn = app(RecordStockIn::class);
    $stockIn->handle($this->honey, $this->warehouse, '1', '400', $this->owner, batchNumber: 'H-400', occurredAt: '2026-09-01 09:00:00');
    $stockIn->handle($this->honey, $this->warehouse, '20', '450', $this->owner, batchNumber: 'H-450', occurredAt: '2026-09-05 09:00:00');
    $this->batch400 = StockBatch::query()->where('batch_number', 'H-400')->sole();
    $this->batch450 = StockBatch::query()->where('batch_number', 'H-450')->sole();
    $stockIn->handle($this->shutki, $this->warehouse, '5', '1500', $this->owner, batchNumber: 'S-1500', occurredAt: '2026-09-01 09:00:00');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');
    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'user_id' => $this->staffUser->id,
        'employee_code' => 'BDG-000012',
        'first_name' => 'Munna', 'last_name' => 'Islam',
        'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-01', 'is_primary' => true,
    ]);

    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->asStaff = fn () => ($this->as)($this->staffUser);
    $this->asOwner = fn () => ($this->as)($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

function costApply(array $lines, string $mode = 'cash'): int
{
    (test()->asStaff)()->post(route('hrm.staff-purchase.apply'), ['payment_mode' => $mode, 'lines' => $lines])
        ->assertSessionHasNoErrors();

    return (int) StaffPurchaseRequest::query()->latest('id')->value('id');
}

function costApprove(int $id): void
{
    (test()->asOwner)()->post(route('hrm.staff-purchases.approve', $id))->assertSessionHasNoErrors();
}

function costHandOver(int $id, bool $cash = true): void
{
    (test()->asOwner)()->post(route('hrm.staff-purchases.fulfill', $id), $cash ? ['money_account_id' => test()->till->id] : [])
        ->assertSessionHasNoErrors();
}

function roleUser(string $role, array $permissions = []): User
{
    $user = User::factory()->create();
    $user->businesses()->attach(test()->business->id);
    setPermissionsTeamId(test()->business->id);
    if ($permissions !== []) {
        $created = Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => test()->business->id]);
        $created->givePermissionTo($permissions);
    }
    $user->assignRole($role);

    return $user;
}

// ─── E1: the price is the reserved FIFO batch cost ─────────────────────────

it('prices the staff line at the cost of the FIFO head batch reserved at approval, not the newest', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    $line = StaffPurchaseRequest::find($id)->lines->sole();

    // Before approval: an estimate from the current head, not frozen.
    expect((float) $line->staff_price)->toBe(400.0)
        ->and($line->price_frozen_at)->toBeNull()
        ->and(StockReservation::count())->toBe(0);

    costApprove($id);
    $line->refresh();
    $reservation = StockReservation::query()->sole();

    expect((float) $line->staff_price)->toBe(400.0)
        ->and($line->cost_basis_batch_id)->toBe($this->batch400->id)
        ->and($line->cost_snapshot['basis'])->toBe('fifo_reserved')
        ->and($line->cost_snapshot['batches'][0]['batch_number'])->toBe('H-400')
        ->and($line->price_frozen_at)->not->toBeNull()
        ->and($reservation->stock_batch_id)->toBe($this->batch400->id)
        ->and((float) $reservation->quantity)->toBe(1.0)
        ->and($reservation->released_at)->toBeNull()
        ->and(StaffPurchaseRequest::find($id)->price_basis)->toBe('fifo_reserved');

    // The counter cannot take the reserved unit: 21 on the shelf, 20 free.
    expect($this->honey->fresh()->sellableStockQuantity())->toBe('20');
    expect(fn () => app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '21']], createdBy: $this->owner, moneyAccount: $this->till,
    ))->toThrow(InsufficientStockException::class);

    // And a sale of what is free skips the reserved batch entirely.
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '2']], createdBy: $this->owner, moneyAccount: $this->till,
    );
    expect(StockMovement::query()->where('reason', 'sale')->pluck('batch_id')->unique()->all())->toBe([$this->batch450->id]);

    // Handover issues the reserved batch at the frozen price, to the employee's own customer.
    costHandOver($id);
    $request = StaffPurchaseRequest::find($id);
    $invoice = SalesInvoice::find($request->sales_invoice_id);
    $customer = Customer::ofEmployee($this->employee->id);

    expect($request->status)->toBe('fulfilled')
        ->and($customer)->not->toBeNull()
        ->and($customer->type->value)->toBe('staff')
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and((float) $invoice->total)->toBe(400.0)
        ->and((bool) $invoice->is_staff_sale)->toBeTrue()
        ->and((float) $invoice->lines->sole()->list_price)->toBe(850.0)
        ->and((bool) $invoice->lines->sole()->is_staff_sale)->toBeTrue()
        ->and($this->batch400->fresh()->current_quantity)->toBe('0.0000')
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($request->events()->pluck('action')->all())->toBe(['applied', 'approved', 'handed_over']);
});

it('sends the request back for re-approval when the reserved batch is repriced before the handover, never recomputing silently', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);

    // A landed cost lands on the reserved batch after approval.
    StockBatch::query()->whereKey($this->batch400->id)->update(['unit_cost' => '480.0000']);

    ($this->asOwner)()->post(route('hrm.staff-purchases.fulfill', $id), ['money_account_id' => $this->till->id])
        ->assertSessionHasErrors('request');

    $request = StaffPurchaseRequest::find($id);
    expect($request->status)->toBe('needs_reapproval')
        ->and($request->reapproval_reason)->toContain('repriced from 400.00 to 480.00')
        ->and((float) $request->lines->sole()->staff_price)->toBe(400.0)
        ->and(StockReservation::query()->live()->count())->toBe(0)
        ->and(SalesInvoice::count())->toBe(0);

    // Approved again: the NEW price, the old one kept in the audit.
    costApprove($id);
    $request->refresh();
    expect($request->status)->toBe('approved')
        ->and((float) $request->lines->sole()->staff_price)->toBe(480.0)
        ->and($request->events()->where('action', 'needs_reapproval')->sole()->details['old_prices'])->toBe([$request->lines->sole()->id => '400.00']);

    costHandOver($id);
    expect((float) SalesInvoice::query()->sole()->total)->toBe(480.0);
});

it('sends the request back for re-approval when a sale took the reserved batch first', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);

    // Somebody issues the reserved unit by hand (a write-off naming the batch).
    StockBatch::query()->whereKey($this->batch400->id)->update(['current_quantity' => '0.0000', 'status' => 'depleted']);

    ($this->asOwner)()->post(route('hrm.staff-purchases.fulfill', $id), ['money_account_id' => $this->till->id])
        ->assertSessionHasErrors('request');

    expect(StaffPurchaseRequest::find($id)->status)->toBe('needs_reapproval');

    // Re-approval reserves the next batch and prices at ITS cost.
    costApprove($id);
    expect((float) StaffPurchaseRequest::find($id)->lines->sole()->staff_price)->toBe(450.0);
});

// ─── E4: the limits ─────────────────────────────────────────────────────────

it('measures the monthly cap at the reference price and counts approved reservations until they are cancelled', function () {
    $honey = costApply([['product_id' => $this->honey->id]]);
    costApprove($honey);

    // Shutki 2,500 + honey 850 reserved = 3,350 > 3,000: refused.
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), ['payment_mode' => 'cash', 'lines' => [['product_id' => $this->shutki->id]]])
        ->assertSessionHasErrors('lines');

    // Cancelled: the reservation is released and nothing counts.
    ($this->asOwner)()->post(route('hrm.staff-purchases.cancel', $honey), ['decision_note' => 'changed his mind'])->assertSessionHasNoErrors();
    expect(StockReservation::query()->live()->count())->toBe(0)
        ->and(StaffPurchaseRequest::find($honey)->status)->toBe('cancelled');

    costApply([['product_id' => $this->shutki->id]]);
    expect(StaffPurchaseRequest::count())->toBe(2);
});

it('prices the second unit of a SKU in the month at the reference price, and refuses a product with no reference price', function () {
    costApply([['product_id' => $this->honey->id]]);
    $second = costApply([['product_id' => $this->honey->id]]);
    $line = StaffPurchaseRequest::find($second)->lines->sole();

    expect($line->priced_at)->toBe('reference')
        ->and((float) $line->staff_price)->toBe(850.0)
        ->and((float) $line->cap_reference_price)->toBe(850.0);

    costApprove($second);
    expect((float) $line->fresh()->staff_price)->toBe(850.0)
        ->and($line->fresh()->cost_snapshot['basis'])->toBe('reference');

    // No MRP and no price in force: not in the benefit at all.
    $ghee = ($this->makeProduct)('GHEE', 'Ghee', null, null);
    app(RecordStockIn::class)->handle($ghee, $this->warehouse, '3', '700', $this->owner);
    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), ['payment_mode' => 'cash', 'lines' => [['product_id' => $ghee->id]]])
        ->assertSessionHasErrors('lines');
});

it('labels a market-priced product\'s cap reference as the current selling price, never as MRP', function () {
    $id = costApply([['product_id' => $this->shutki->id]]);
    $line = StaffPurchaseRequest::find($id)->lines->sole();

    expect($line->cap_reference_label)->toBe('reference')
        ->and((float) $line->cap_reference_price)->toBe(2500.0)
        ->and(ProductPricing::capReference($this->honey))->toBe(['amount' => '850.00', 'label' => 'mrp']);

    // Frozen: a later price change does not rewrite the request's reference.
    ProductPrice::query()->where('product_id', $this->shutki->id)->update(['unit_price' => '2900']);
    expect((float) $line->fresh()->cap_reference_price)->toBe(2500.0);
});

it('refuses the second of two requests racing the same month over the cap, under the month lock', function () {
    // Staged race: the moment this request takes the month lock, the other
    // request (honey, 850) commits underneath it. The check that follows the
    // lock must see it and refuse shutki (2,500 + 850 > 3,000).
    $staged = false;
    DB::listen(function ($query) use (&$staged) {
        if (! $staged && str_contains($query->sql, 'staff_purchase_month_counters') && str_starts_with($query->sql, 'select')) {
            $staged = true;
            $id = DB::table('staff_purchase_requests')->insertGetId([
                'business_id' => $this->business->id, 'number' => 'SPB-RACE-1', 'employee_id' => $this->employee->id,
                'warehouse_id' => $this->warehouse->id, 'payment_mode' => 'cash', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('staff_purchase_lines')->insert([
                'business_id' => $this->business->id, 'request_id' => $id, 'product_id' => $this->honey->id, 'quantity' => 1,
                'staff_price' => 400, 'mrp' => 850, 'cap_reference_price' => 850, 'cap_reference_label' => 'mrp', 'priced_at' => 'staff',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    ($this->asStaff)()->post(route('hrm.staff-purchase.apply'), ['payment_mode' => 'cash', 'lines' => [['product_id' => $this->shutki->id]]])
        ->assertSessionHasErrors('lines');

    // ⚠ The refusal rolled the transaction back, and on the one in-memory
    // SQLite connection the staged row went with it (in production the other
    // request's commit is its own). What this proves is the order: the lock
    // was taken, the competing request was then visible, and shutki was refused.
    expect($staged)->toBeTrue()
        ->and(StaffPurchaseRequest::count())->toBe(0)
        ->and(StockReservation::count())->toBe(0);
});

// ─── E3: the payroll offset ────────────────────────────────────────────────

function costPayrollRun(PayrollPeriod $period, float $gross, float $recovery, int $version = 1): PayrollRun
{
    $run = PayrollRun::create([
        'business_id' => test()->business->id, 'payroll_period_id' => $period->id,
        'version' => $version, 'status' => PayrollStatus::Reviewed,
    ]);
    $payslip = $run->payslips()->create([
        'employee_id' => test()->employee->id,
        'gross_salary' => $gross, 'total_earnings' => $gross, 'total_deductions' => $recovery, 'net' => $gross - $recovery,
    ]);
    $payslip->lines()->create([
        'code' => 'basic', 'label' => 'Basic', 'type' => SalaryComponentType::Earning,
        'amount' => $gross, 'formula' => 'structure', 'source' => 'structure', 'sequence' => 0,
    ]);
    if ($recovery > 0) {
        $payslip->lines()->create([
            'code' => 'staff_purchase', 'label' => 'Staff purchase recovery', 'type' => SalaryComponentType::Deduction,
            'amount' => $recovery, 'formula' => '10% of net pay cap', 'source' => 'staff_purchase', 'sequence' => 1,
        ]);
    }

    return $run;
}

function costOn(Journal $journal, string $slug): int
{
    return (int) JournalLine::query()->where('journal_id', $journal->id)->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
}

it('settles a salary-deduction invoice from payroll as an invoice-linked offset: 2130 down, 1140 down, 2140 untouched, partial carried forward', function () {
    $id = costApply([['product_id' => $this->honey->id]], 'salary_deduction');
    costApprove($id);
    costHandOver($id, cash: false);

    $invoice = SalesInvoice::query()->sole();
    $customer = Customer::ofEmployee($this->employee->id);
    expect((float) $invoice->outstanding()->toTaka())->toBe(400.0)
        ->and($invoice->customer_id)->toBe($customer->id);

    // August (already ended on 23 Aug): gross 2,000, the 10% cap lets payroll take 200 of the 400.
    $august = PayrollPeriod::forMonth(2026, 8);
    $run = costPayrollRun($august, 2000, 200);
    app(TransitionPayrollRun::class)->markApproved($run->fresh(), $this->owner);

    $accrual = Journal::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->id)->sole();
    $offset = CustomerReceipt::query()->where('kind', 'payroll_offset')->sole();
    $offsetJournal = Journal::query()->where('source_type', $offset->getMorphClass())->where('source_id', $offset->id)->sole();

    expect($offset->money_account_id)->toBeNull()
        ->and($offset->payroll_run_id)->toBe($run->id)
        ->and((float) $offset->amount)->toBe(200.0)
        ->and($offset->allocations->sole()->sales_invoice_id)->toBe($invoice->id)
        ->and((float) $invoice->fresh()->outstanding()->toTaka())->toBe(200.0)
        // The accrual: Dr 6131 2,000 / Cr 2130 2,000 (net BEFORE the offset), nothing on 2140.
        ->and(costOn($accrual, 'salaries_expense'))->toBe(200000)
        ->and(costOn($accrual, 'salaries_payable'))->toBe(-200000)
        ->and(JournalLine::query()->where('journal_id', $accrual->id)->where('account_id', Account::bySlug('employee_deductions_payable')->id)->exists())->toBeFalse()
        // The offset: Dr 2130 200 (party employee) / Cr 1140 200 (party customer).
        ->and(costOn($offsetJournal, 'salaries_payable'))->toBe(20000)
        ->and(costOn($offsetJournal, 'accounts_receivable'))->toBe(-20000)
        ->and(JournalLine::query()->where('journal_id', $offsetJournal->id)->where('account_id', Account::bySlug('accounts_receivable')->id)->sole()->party_id)->toBe($customer->id)
        ->and($offsetJournal->balance()->isZero())->toBeTrue()
        // Together: 2130 carries exactly the net paid, 1,800.
        ->and(costOn($accrual, 'salaries_payable') + costOn($offsetJournal, 'salaries_payable'))->toBe(-180000)
        ->and(StaffPurchaseRequest::find($id)->events()->where('action', 'payroll_offset')->exists())->toBeTrue();

    // September takes the carried 200 and the invoice is settled.
    $september = PayrollPeriod::forMonth(2026, 9);
    $run2 = costPayrollRun($september, 2000, 200);
    app(TransitionPayrollRun::class)->markApproved($run2->fresh(), $this->owner);

    expect((float) $invoice->fresh()->outstanding()->toTaka())->toBe(0.0)
        ->and(CustomerReceipt::query()->where('kind', 'payroll_offset')->count())->toBe(2);

    // Reopening September takes its offset back out: the invoice owes 200 again.
    app(TransitionPayrollRun::class)->reopen($run2->fresh(), $this->owner, 'figures changed');

    expect(CustomerReceipt::query()->where('kind', 'payroll_offset')->count())->toBe(1)
        ->and((float) $invoice->fresh()->outstanding()->toTaka())->toBe(200.0)
        ->and(Journal::query()->where('reverses_journal_id', '!=', null)->count())->toBeGreaterThanOrEqual(2);
});

it('runs the read-only 2140 audit command and writes nothing', function () {
    $before = DB::table('journals')->count();

    $this->artisan('staff-purchases:audit-2140')->assertSuccessful();

    expect(DB::table('journals')->count())->toBe($before);
});

// ─── E5: confidentiality ───────────────────────────────────────────────────

it('shows the cost basis only to holders of the cost-basis permission, and never to the requester', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);

    // The Owner holds hrm.staff_purchase.cost_basis.view (seeded).
    ($this->asOwner)()->get(route('hrm.staff-purchases.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeCostBasis', true)
            ->where('requests.0.lines.0.cost_basis.batch', 'H-400')
            ->where('requests.0.lines.0.cost_basis.unit_cost', '400.0000'));

    // An approver with only hrm.employees.manage sees the approved price, no basis.
    $storeHr = roleUser('Store HR', ['hrm.employees.manage']);
    ($this->as)($storeHr)->get(route('hrm.staff-purchases.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeCostBasis', false)
            ->where('requests.0.lines.0.staff_price', fn ($v) => (float) $v === 400.0)
            ->where('requests.0.lines.0.cost_basis', null));

    // Finance holds it (seeded to the Finance role).
    $finance = roleUser('Finance');
    expect($finance->can('hrm.staff_purchase.cost_basis.view'))->toBeTrue();

    // The requester sees "Staff price Tk X" and nothing of the batch.
    ($this->asStaff)()->get(route('hrm.staff-purchase'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.lines.0.staff_price', fn ($v) => (float) $v === 400.0)
            ->where('requests.0.lines.0.cost_basis', null));
});

it('keeps the employee customer out of general pickers and pages for roles without the permission', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);
    costHandOver($id);
    $customer = Customer::ofEmployee($this->employee->id);

    $seller = roleUser('Counter', ['sales.sell', 'sales.reports.view']);
    $visible = Customer::query()->listable($seller)->pluck('id')->all();
    $ownerSees = Customer::query()->listable($this->owner)->pluck('id')->all();

    expect($visible)->not->toContain($customer->id)
        ->and($ownerSees)->toContain($customer->id);

    ($this->as)($seller)->get(route('sales.customers.show', $customer))->assertForbidden();
    ($this->asOwner)()->get(route('sales.customers.show', $customer))->assertOk();
});

// ─── E2: out of the retail KPIs ────────────────────────────────────────────

it('flags the staff sale and leaves it out of the retail KPIs while the ledger keeps it', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);
    costHandOver($id);

    // A real retail sale beside it.
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '1']], createdBy: $this->owner, moneyAccount: $this->till,
    );

    $facts = new SalesFacts($this->business->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect(SalesInvoice::query()->retail()->count())->toBe(1)
        ->and(SalesInvoice::count())->toBe(2)
        ->and($facts->invoiceLines()->count())->toBe(1)
        ->and(SalesTargetProgress::actualFor($this->business->id, $this->owner->id, '2026-10-01', '2026-10-31'))->toBe(850.0)
        // The ledger saw both: revenue 400 + 850.
        ->and((int) JournalLine::query()->where('account_id', Account::bySlug('sales_revenue')->id)->sum('amount_minor'))->toBe(-125000);
});

// ─── E6: defect replacement ────────────────────────────────────────────────

it('replaces a defective unit at zero charge, linked to the request, and refuses a return', function () {
    $id = costApply([['product_id' => $this->honey->id]]);
    costApprove($id);
    costHandOver($id);
    $request = StaffPurchaseRequest::find($id);
    $line = $request->lines->sole();

    // Reason and evidence are required.
    ($this->asOwner)()->post(route('hrm.staff-purchases.replace', $id), ['line_id' => $line->id, 'reason' => 'seal broken'])
        ->assertSessionHasErrors('evidence_note');

    ($this->asOwner)()->post(route('hrm.staff-purchases.replace', $id), [
        'line_id' => $line->id, 'reason' => 'seal broken', 'evidence_note' => 'jar seen at the counter, photo in the group',
    ])->assertSessionHasNoErrors();

    $replacement = StaffPurchaseReplacement::query()->sole();
    $movement = StockMovement::find($replacement->stock_movement_id);
    $journal = Journal::find($replacement->journal_id);

    expect($replacement->request_id)->toBe($id)
        ->and($replacement->line_id)->toBe($line->id)
        ->and($replacement->defective_disposition)->toBe('write_off')
        ->and($movement->reason->value)->toBe('staff_replacement')
        ->and((float) $movement->unit_cost)->toBe(450.0)
        ->and(SalesInvoice::count())->toBe(1)
        ->and($this->honey->fresh()->currentStockQuantity())->toBe('19')
        ->and(costOn($journal, 'inventory_write_off'))->toBe(45000)
        ->and(costOn($journal, 'stock_on_hand'))->toBe(-45000)
        // No quota used: the replacement is not a line of any request.
        ->and(StaffPurchaseRequest::query()->counting()->count())->toBe(1);

    // The same unit is not replaced twice.
    ($this->asOwner)()->post(route('hrm.staff-purchases.replace', $id), [
        'line_id' => $line->id, 'reason' => 'again', 'evidence_note' => 'again',
    ])->assertSessionHasErrors('line');

    // No credit note path for a staff sale.
    $delivery = Delivery::query()->sole();
    try {
        app(RecordSalesReturn::class)->handle($delivery, [[
            'deliveryLine' => $delivery->lines->sole(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0',
        ]], $this->owner);
        $this->fail('A staff purchase must not be returned for credit.');
    } catch (ValidationException $e) {
        expect($e->errors()['lines'][0])->toContain('staff purchase')->toContain('Replace, defect');
    }
});
