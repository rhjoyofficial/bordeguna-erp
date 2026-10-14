<?php

use App\Models\User;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\CarryForwardNegativeNet;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\PostLoanRepayments;
use App\Modules\Hrm\Actions\RequestLoan;
use App\Modules\Hrm\Actions\RequestPayrollAdjustment;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\SettleLoan;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Lend Co', 'slug' => 'lend-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 25000],
    ]);

    // The payroll month the loan schedule will start in. "Today" in the test
    // suite is the real clock, so the start month is derived rather than fixed.
    $this->startYear = (int) now()->year;
    $this->startMonth = (int) now()->month;
    $this->period = PayrollPeriod::forMonth($this->startYear, $this->startMonth);
});

function loanHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function requestLoan(float $principal = 60000, float $installment = 5000, LoanType $type = LoanType::Loan): Loan
{
    return app(RequestLoan::class)->handle(
        test()->employee, $type, $principal, $installment,
        test()->startYear, test()->startMonth, 'Family medical expenses', test()->hr,
    );
}

function disbursedLoan(float $principal = 60000, float $installment = 5000): Loan
{
    $loan = requestLoan($principal, $installment);
    approveIfPending($loan->approvalRequest, test()->hr);

    return app(DisburseLoan::class)->handle($loan);
}

/** Walk a run all the way to Disbursed — the only point money is real. */
function disburseRun(PayrollRun $run): PayrollRun
{
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, test()->hr);
    approveIfPending($run->fresh()->approvalRequest, test()->hr);
    $transition->markApproved($run->fresh(), test()->hr);
    $transition->disburse($run->fresh(), test()->hr);

    return $run->fresh();
}

// --- ADJ-07: request and approval ------------------------------------------------

it('owes nothing while a loan is only requested', function () {
    $loan = requestLoan();

    expect($loan->status)->toBe(LoanStatus::Pending);
    expect($loan->outstanding())->toBe(0.0);
    expect($loan->ledgerEntries()->count())->toBe(0);
    expect($loan->installments()->count())->toBe(0);
});

it('disburses and schedules on approval', function () {
    $loan = disbursedLoan();

    expect($loan->status)->toBe(LoanStatus::Active);
    expect($loan->outstanding())->toBe(60000.0);
    expect($loan->installments()->count())->toBe(12);
    expect($loan->ledgerEntries()->sole()->entry_type)->toBe(LoanLedgerEntryType::Disbursement);
});

it('creates nothing when a loan is rejected', function () {
    $loan = requestLoan();

    loanHr()->post(route('hrm.loans.reject', $loan))->assertRedirect();

    expect($loan->fresh()->status)->toBe(LoanStatus::Rejected);
    expect(LoanLedgerEntry::count())->toBe(0);
    expect($loan->fresh()->installments()->count())->toBe(0);
});

it('disburses at most once, however often it is applied', function () {
    $loan = requestLoan();
    approveIfPending($loan->approvalRequest, $this->hr);

    app(DisburseLoan::class)->handle($loan);
    app(DisburseLoan::class)->handle($loan->fresh());
    app(DisburseLoan::class)->handle($loan->fresh());

    expect(LoanLedgerEntry::count())->toBe(1);
    expect($loan->fresh()->installments()->count())->toBe(12);
    expect($loan->fresh()->outstanding())->toBe(60000.0);
});

it('refuses a second open loan of the same kind', function () {
    disbursedLoan();

    expect(fn () => requestLoan())->toThrow(ValidationException::class);
});

it('allows an advance alongside an open loan, since they are different agreements', function () {
    disbursedLoan();

    $advance = requestLoan(10000, 10000, LoanType::Advance);

    expect($advance->type)->toBe(LoanType::Advance);
});

it('refuses terms that cannot produce a schedule', function () {
    // An installment larger than the principal is not a schedule.
    expect(fn () => requestLoan(5000, 6000))->toThrow(ValidationException::class);
});

it('refuses a start month that has already passed', function () {
    expect(fn () => app(RequestLoan::class)->handle(
        $this->employee, LoanType::Loan, 60000, 5000, 2020, 1, 'Too late', $this->hr,
    ))->toThrow(ValidationException::class);
});

it('puts a pending loan in the shared approval inbox', function () {
    requestLoan();

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->hr);

    expect($actionable)->toHaveCount(1);
    expect($actionable->first()->approvable)->toBeInstanceOf(Loan::class);
});

// --- ADJ-07: payroll auto-deduction ------------------------------------------------

it('deducts the scheduled installment with full provenance', function () {
    disbursedLoan();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $line = $run->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole();

    expect((float) $line->amount)->toBe(5000.0);
    expect($line->label)->toBe('Loan Repayment');
    // What a borrower actually asks: which loan, how far through, what is left.
    expect((float) $line->quantity)->toBe(1.0);
    expect((float) $line->rate)->toBe(5000.0);
    expect($line->formula)->toContain('installment 1 of 12')->toContain('55,000.00 outstanding after this');
    expect($line->source)->toStartWith('loan_installment:');
});

it('deducts nothing for a loan that was never disbursed', function () {
    requestLoan();

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->payslips()->sole()->lines()->where('code', 'loan_repayment')->exists())->toBeFalse();
});

it('labels a salary advance as a recovery rather than a loan repayment', function () {
    $advance = requestLoan(10000, 10000, LoanType::Advance);
    approveIfPending($advance->approvalRequest, $this->hr);
    app(DisburseLoan::class)->handle($advance);

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole()->label)
        ->toBe('Salary Advance Recovery');
});

// --- Non-circularity: the property this slice depends on ------------------------------

it('produces an identical deduction however many times an open run is recalculated', function () {
    disbursedLoan();

    $amounts = [];

    for ($i = 0; $i < 3; $i++) {
        $run = app(CalculatePayroll::class)->handle($this->period);
        $amounts[] = (float) $run->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole()->amount;
    }

    // If a repayment posted at calculation, the balance would fall each time and
    // the figures would move under HR's feet.
    expect($amounts)->toBe([5000.0, 5000.0, 5000.0]);
    expect(Loan::sole()->outstanding())->toBe(60000.0);
});

it('reduces the balance only when the payroll is disbursed', function () {
    $loan = disbursedLoan();

    $run = app(CalculatePayroll::class)->handle($this->period);
    expect($loan->fresh()->outstanding())->toBe(60000.0);

    disburseRun($run);

    expect($loan->fresh()->outstanding())->toBe(55000.0);
    expect($loan->fresh()->installments()->where('status', LoanInstallmentStatus::Paid)->count())->toBe(1);
});

it('records a repayment exactly once for an installment', function () {
    $loan = disbursedLoan();
    $run = disburseRun(app(CalculatePayroll::class)->handle($this->period));

    // Re-posting is what a retried job or a repeated transition would do.
    app(PostLoanRepayments::class)->handle($run);
    app(PostLoanRepayments::class)->handle($run);

    expect(LoanLedgerEntry::where('entry_type', LoanLedgerEntryType::Repayment)->count())->toBe(1);
    expect($loan->fresh()->outstanding())->toBe(55000.0);
});

it('moves to the next installment in the next payroll month', function () {
    $loan = disbursedLoan();
    disburseRun(app(CalculatePayroll::class)->handle($this->period));

    $next = PayrollPeriod::forMonth(
        $this->startMonth === 12 ? $this->startYear + 1 : $this->startYear,
        $this->startMonth === 12 ? 1 : $this->startMonth + 1,
    );
    $run = app(CalculatePayroll::class)->handle($next);
    $line = $run->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole();

    expect((float) $line->quantity)->toBe(2.0);
    expect($line->formula)->toContain('installment 2 of 12')->toContain('50,000.00 outstanding after this');
});

// --- ADJ-07: early settlement ---------------------------------------------------------

it('clears the balance and cancels the remaining schedule on early settlement', function () {
    $loan = disbursedLoan();
    disburseRun(app(CalculatePayroll::class)->handle($this->period));

    $settled = app(SettleLoan::class)->handle($loan->fresh(), 55000, 'Paid by bank transfer');

    expect($settled->status)->toBe(LoanStatus::Settled);
    expect($settled->outstanding())->toBe(0.0);
    expect($settled->installments()->where('status', LoanInstallmentStatus::Paid)->count())->toBe(1);
    // Cancelled, not deleted — the original plan stays readable.
    expect($settled->installments()->where('status', LoanInstallmentStatus::Cancelled)->count())->toBe(11);
    expect($settled->installments()->count())->toBe(12);
});

it('refuses early settlement while an unpaid payroll already deducts an installment', function () {
    // ⚠ Audit 13 Oct 2026: settling here cleared the balance and the
    // approved July payroll then collected the installment again.
    $loan = disbursedLoan();
    $run = app(CalculatePayroll::class)->handle($this->period);
    expect($run->payslips()->sole()->lines()->where('code', 'loan_repayment')->exists())->toBeTrue();

    expect(fn () => app(SettleLoan::class)->handle($loan->fresh(), 60000, 'Paid by bank transfer'))
        ->toThrow(ValidationException::class, 'collected twice');
    expect($loan->fresh()->status)->toBe(LoanStatus::Active);

    // Once that payroll is paid, the rest can be settled as before.
    disburseRun($run->fresh());
    expect(app(SettleLoan::class)->handle($loan->fresh(), 55000, 'Paid by bank transfer')->status)
        ->toBe(LoanStatus::Settled);
});

it('refuses a part payment dressed up as a settlement', function () {
    $loan = disbursedLoan();

    expect(fn () => app(SettleLoan::class)->handle($loan, 10000, 'Partial'))
        ->toThrow(ValidationException::class);
});

it('stops deducting once a loan is settled', function () {
    $loan = disbursedLoan();
    app(SettleLoan::class)->handle($loan, 60000, 'Paid in full by transfer');

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->payslips()->sole()->lines()->where('code', 'loan_repayment')->exists())->toBeFalse();
});

it('closes a loan by itself once the final installment is collected', function () {
    // One installment covering the whole principal.
    $loan = disbursedLoan(5000, 5000);
    disburseRun(app(CalculatePayroll::class)->handle($this->period));

    $loan = $loan->fresh();
    expect($loan->outstanding())->toBe(0.0);
    expect($loan->status)->toBe(LoanStatus::Settled);
    expect($loan->settlement_note)->toBe('Repaid in full through payroll.');
});

// --- Interaction with PAY-11's carry-forward (no hidden or circular deductions) --------

it('keeps loan recovery and carry-forward recovery as separate, explainable lines', function () {
    // A loan installment plus a large penalty pushes the net negative.
    disbursedLoan();

    $penalty = app(RequestPayrollAdjustment::class)->handle(
        $this->employee, PayrollAdjustmentType::Penalty, 80000, $this->period,
        'Disciplinary', UploadedFile::fake()->create('e.pdf', 5), $this->hr,
    );
    approveIfPending($penalty->approvalRequest, $this->hr);

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect($payslip->is_blocked)->toBeTrue();
    $shortfall = round(-1 * (float) $payslip->net, 2);

    // PAY-11's human decision.
    app(CarryForwardNegativeNet::class)->handle($payslip);
    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect((float) $payslip->net)->toBe(0.0);
    // Both deductions are still there, each on its own line, neither netted
    // against the other nor derived from the other.
    expect((float) $payslip->lines()->where('code', 'loan_repayment')->sole()->amount)->toBe(5000.0);
    expect((float) $payslip->lines()->where('code', 'penalty')->sole()->amount)->toBe(80000.0);
    expect((float) $payslip->lines()->where('code', 'balance_carry_forward')->sole()->amount)->toBe($shortfall);

    disburseRun($run);

    // The loan was genuinely recovered; the shortfall genuinely carried.
    expect(Loan::sole()->outstanding())->toBe(55000.0);
    expect(PayrollBalanceEntry::outstandingFor($this->employee->id))->toBe($shortfall);
});

it('does not let a carried-forward balance change the loan installment', function () {
    // The two ledgers are independent: neither reads the other.
    disbursedLoan();

    PayrollBalanceEntry::create([
        'employee_id' => $this->employee->id,
        'entry_type' => PayrollBalanceEntryType::CarriedForward,
        'amount' => 7000,
        'occurred_on' => now()->subYear()->toDateString(),
        'note' => 'Older shortfall',
        'source_key' => 'test:older',
    ]);

    $run = app(CalculatePayroll::class)->handle($this->period);
    $lines = $run->payslips()->sole()->lines;

    expect((float) $lines->where('code', 'loan_repayment')->sole()->amount)->toBe(5000.0);
    expect((float) $lines->where('code', 'balance_recovery')->sole()->amount)->toBe(7000.0);
});

// --- Access and screens ----------------------------------------------------------------

it('blocks a user without the payroll permission', function () {
    $this->actingAs($this->staffUser)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.loans.index'))->assertForbidden();
});

it('separates requesting a loan from approving it', function () {
    $preparer = User::factory()->create();
    $preparer->businesses()->attach($this->business->id);
    $role = Role::firstOrCreate(['name' => 'Payroll Officer', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('hrm.payroll.manage');
    $preparer->assignRole('Payroll Officer');

    $loan = requestLoan();

    $this->actingAs($preparer)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.loans.approve', $loan))->assertForbidden();
});

it('renders the loans screen with its schedule and ledger', function () {
    disbursedLoan();

    loanHr()->get(route('hrm.loans.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/loans')
            ->has('loans', 1)
            ->where('loans.0.outstanding', 60000)
            ->has('loans.0.installments', 12)
            ->has('loans.0.ledger', 1)
            ->has('employees')
            ->has('types'));
});

it('requests a loan through the real HTTP route', function () {
    loanHr()->post(route('hrm.loans.store'), [
        'employee_id' => $this->employee->id,
        'type' => 'loan',
        'principal' => 60000,
        'installment_amount' => 5000,
        'start_year' => $this->startYear,
        'start_month' => $this->startMonth,
        'reason' => 'Family medical expenses',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Loan::count())->toBe(1);
});

it('previews a schedule before anyone commits to it', function () {
    loanHr()->get(route('hrm.loans.preview', [
        'principal' => 50000, 'installment_amount' => 4166.67,
        'start_year' => 2026, 'start_month' => 8,
    ]))
        ->assertOk()
        ->assertJsonPath('count', 12)
        ->assertJsonPath('total', 50000);
});

// --- Recovering from a month the calendar has passed but payroll has not ---------

it('recovers from a payroll month that is late but still unpaid', function () {
    // ⚠⚠ The real case, 1 September 2026. August payroll was calculated,
    // reviewed and approved but NOT paid, and three advances handed out during
    // August had to come out of it. The old rule refused any month before this
    // one and stranded all three.
    $last = now()->subMonthNoOverflow();
    $period = PayrollPeriod::forMonth((int) $last->year, (int) $last->month);
    PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => PayrollStatus::Approved,
        'employee_count' => 1,
        'total_earnings' => 50000,
        'total_deductions' => 0,
        'total_net' => 50000,
    ]);

    $loan = app(RequestLoan::class)->handle(
        $this->employee, LoanType::Advance, 12400, 12400,
        (int) $last->year, (int) $last->month, 'Advance taken during the month', $this->hr,
    );

    expect($loan->start_year)->toBe((int) $last->year)
        ->and($loan->start_month)->toBe((int) $last->month)
        ->and($loan->installment_count)->toBe(1);
});

it('refuses to recover from a payroll month that has already been paid', function () {
    $last = now()->subMonthNoOverflow();
    $period = PayrollPeriod::forMonth((int) $last->year, (int) $last->month);
    PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => PayrollStatus::Disbursed,
        'employee_count' => 1,
        'total_earnings' => 50000,
        'total_deductions' => 0,
        'total_net' => 50000,
    ]);

    // Paid is paid. WFL-06 sends the difference to the next payroll instead.
    expect(fn () => app(RequestLoan::class)->handle(
        $this->employee, LoanType::Advance, 12400, 12400,
        (int) $last->year, (int) $last->month, 'Too late', $this->hr,
    ))->toThrow(ValidationException::class);
});

it('deducts a late advance from the unpaid month it was recovered against', function () {
    // The whole point, followed to the payslip: an advance dated to a month the
    // calendar has left behind must actually come off that month's salary.
    $last = now()->subMonthNoOverflow();
    $period = PayrollPeriod::forMonth((int) $last->year, (int) $last->month);

    $loan = app(RequestLoan::class)->handle(
        $this->employee, LoanType::Advance, 12400, 12400,
        (int) $last->year, (int) $last->month, 'Advance taken during the month', $this->hr,
    );
    approveIfPending($loan->approvalRequest, $this->hr);
    app(DisburseLoan::class)->handle($loan, null, $this->hr, $last->copy()->day(17)->toDateString());

    $run = app(CalculatePayroll::class)->handle($period->fresh());
    $payslip = $run->payslips()->where('employee_id', $this->employee->id)->sole();
    $line = $payslip->lines->firstWhere('code', 'loan_repayment');

    expect($line)->not->toBeNull()
        ->and((float) $line->amount)->toBe(12400.0)
        ->and((float) $payslip->total_deductions)->toBe(12400.0);
});

it('asks which account the money came from before it records the approval', function () {
    // ⚠⚠ The screen used to post nothing. The approval went through and the
    // disbursement then threw for want of an account, leaving a loan approved
    // and unpaid behind a blank error page.
    $loan = requestLoan();

    loanHr()->post(route('hrm.loans.approve', $loan))
        ->assertSessionHasErrors('money_account_id');

    expect($loan->fresh()->status)->toBe(LoanStatus::Pending)
        ->and($loan->fresh()->approvalRequest->status->value)->toBe('pending')
        ->and($loan->fresh()->installments()->count())->toBe(0);
});

it('carries the account list to the screen that has to ask for it', function () {
    loanHr()->get(route('hrm.loans.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('moneyAccounts'));
});

// --- Making the screens land where the work is -----------------------------------

it('offers the earliest unpaid payroll month, not this calendar month', function () {
    // Joy, 1 September 2026: the August payroll was still unpaid, and every
    // advance handed out during August had to come off it. A form defaulting to
    // September would have set the schedule a month late, silently.
    $last = now()->subMonthNoOverflow();
    $period = PayrollPeriod::forMonth((int) $last->year, (int) $last->month);
    PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => PayrollStatus::Approved,
        'employee_count' => 1,
        'total_earnings' => 50000,
        'total_deductions' => 0,
        'total_net' => 50000,
    ]);

    loanHr()->get(route('hrm.loans.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recoverableFrom.year', (int) $last->year)
            ->where('recoverableFrom.month', (int) $last->month));
});

it('moves on to a month that can still carry it once every payroll is paid', function () {
    $last = now()->subMonthNoOverflow();
    $period = PayrollPeriod::forMonth((int) $last->year, (int) $last->month);
    PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => PayrollStatus::Disbursed,
        'employee_count' => 1,
        'total_earnings' => 50000,
        'total_deductions' => 0,
        'total_net' => 50000,
    ]);

    // `$this->period` (this month) was created in the setup and has no run, so
    // it is unpaid and is the first month that can still carry a repayment.
    loanHr()->get(route('hrm.loans.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recoverableFrom.year', (int) now()->year)
            ->where('recoverableFrom.month', (int) now()->month));
});

it('picks a month by name rather than by number', function () {
    // ⚠ The VALUE stays the number every payroll route expects; only the
    // reading changes. A control that changed the value would have meant
    // touching the server for a wording fix.
    $source = file_get_contents(resource_path('js/components/month-select.tsx'));

    expect($source)->toContain("'September'")
        ->and(file_get_contents(resource_path('js/pages/hrm/payroll/loans.tsx')))->toContain('<MonthSelect id="loan_month"')
        ->and(file_get_contents(resource_path('js/pages/hrm/payroll/index.tsx')))->toContain('<MonthSelect id="month"');
});

it('does not offer an inbox Approve that would disburse without naming an account', function () {
    $loan = requestLoan();

    $card = app(ApprovalCards::class)->present($loan->approvalRequest);

    // One stage in the workflow, so this approval is the final one and pays out.
    expect($card['approve_url'])->toBeNull();
    expect($card['reject_url'])->not->toBeNull();
    expect(collect($card['details'])->pluck('label'))->toContain('To approve');
});

it('leaves the loan awaiting its last approver when the payout throws', function () {
    $loan = requestLoan();
    $account = MoneyAccount::create([
        'name' => 'Cash Box',
        'type' => MoneyAccountType::Cash,
    ]);

    $this->mock(DisburseLoan::class)->shouldReceive('handle')->andThrow(new RuntimeException('payout failed'));

    loanHr()->withoutExceptionHandling();
    expect(fn () => loanHr()->post(route('hrm.loans.approve', $loan), ['money_account_id' => $account->id]))
        ->toThrow(RuntimeException::class);

    // The approval rolled back with the payout, so somebody can still act.
    expect($loan->fresh()->approvalRequest->isPending())->toBeTrue();
    expect($loan->fresh()->status)->toBe(LoanStatus::Pending);
});

// --- Rule #21 (14 Oct 2026): an installment in an approved month is carried --

function nextLoanPeriod(int $ahead = 1): PayrollPeriod
{
    $month = now()->setDate(test()->startYear, test()->startMonth, 1)->addMonthsNoOverflow($ahead);

    return PayrollPeriod::forMonth((int) $month->year, (int) $month->month);
}

function approvedEmptyRun(PayrollPeriod $period): PayrollRun
{
    return PayrollRun::create([
        'business_id' => test()->business->id, 'payroll_period_id' => $period->id, 'version' => 1,
        'status' => PayrollStatus::Approved, 'employee_count' => 1,
        'total_earnings' => 50000, 'total_deductions' => 0, 'total_net' => 50000,
    ]);
}

it('carries an installment whose month was approved without it into the next open payroll', function () {
    $loan = disbursedLoan();
    // The first month was already approved before the loan reached it.
    approvedEmptyRun($this->period);

    $run = app(CalculatePayroll::class)->handle(nextLoanPeriod());
    $lines = $run->payslips()->sole()->lines()->where('code', 'loan_repayment')->orderBy('quantity')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([1.0, 2.0])
        ->and($lines[0]->formula)->toContain('carried forward');

    disburseRun($run);

    expect($loan->fresh()->outstanding())->toBe(50000.0)
        ->and($loan->fresh()->installments()->where('status', LoanInstallmentStatus::Paid)->count())->toBe(2);

    // Exactly once: the month after takes only its own installment.
    $after = app(CalculatePayroll::class)->handle(nextLoanPeriod(2));
    $line = $after->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole();
    expect((float) $line->quantity)->toBe(3.0);
});

it('does not carry an installment the approved month already deducts', function () {
    disbursedLoan();
    $first = app(CalculatePayroll::class)->handle($this->period);
    $transition = app(TransitionPayrollRun::class);
    $transition->review($first, $this->hr);
    approveIfPending($first->fresh()->approvalRequest, $this->hr);
    $transition->markApproved($first->fresh(), $this->hr);

    // Approved, not yet paid: the installment is still Scheduled but it is on
    // that payslip, so the next month must not take it again.
    $run = app(CalculatePayroll::class)->handle(nextLoanPeriod());

    expect($run->payslips()->sole()->lines()->where('code', 'loan_repayment')->sole()->quantity)->toEqual(2.0);
});

it('keeps a carried installment on a later version of the month that recovered it', function () {
    disbursedLoan();
    approvedEmptyRun($this->period);
    $next = nextLoanPeriod();
    disburseRun(app(CalculatePayroll::class)->handle($next));

    // HRM-002 extended: both installments that month recovered (its own and
    // the carried one) stay on any recalculated version of it.
    $context = app(PayrollContextBuilder::class)->build($this->employee, $next, PayrollConfig::defaults());

    expect(collect($context->loanInstallments)->sum(fn ($input) => $input->amount))->toEqual(10000.0);
});
