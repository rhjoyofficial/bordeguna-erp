<?php

use App\Models\User;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\ConfirmDisbursementBatch;
use App\Modules\Hrm\Actions\CreateDisbursementBatches;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\RecordPayslipPayment;
use App\Modules\Hrm\Actions\RequestLoan;
use App\Modules\Hrm\Actions\RequestPayrollUnlock;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\PayslipPaymentStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Jobs\SendPayslipEmail;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Payroll\Disbursement\AccountingExport;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementFormatRegistry;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementRegister;
use App\Modules\Hrm\Payroll\Payslip\PayslipPassword;
use App\Modules\Hrm\Payroll\Payslip\PayslipPdf;
use App\Modules\Hrm\Reporting\Definitions\UnpaidSalaryReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Pay Co', 'slug' => 'pay-co']);
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

    $this->otherUser = User::factory()->create();
    $this->otherUser->businesses()->attach($this->business->id);
    $this->otherUser->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => (int) now()->year, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    // Paid by bank transfer, with a date of birth — so the SAL-07 password can
    // be formed and the protected PDF is available.
    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '1234567890', 'bank_name' => 'Brac Bank',
        'salary_payment_method' => SalaryPaymentMethod::BankTransfer,
        'date_of_birth' => '1995-12-31',
        'user_id' => $this->staffUser->id,
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    // Paid in cash, so the two land in different batches.
    $this->cashEmployee = Employee::create([
        'employee_code' => 'BDG-000002',
        'first_name' => 'Rahim', 'last_name' => 'Uddin',
        'employment_type' => 'permanent', 'status' => 'active',
        'salary_payment_method' => SalaryPaymentMethod::Cash,
        'date_of_birth' => '1990-01-15',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->cashEmployee->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    foreach ([$this->employee, $this->cashEmployee] as $employee) {
        app(SaveSalaryStructure::class)->handle($employee, '2025-01-01', [
            ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
            ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 25000],
        ]);
    }

    $this->startYear = (int) now()->year;
    $this->startMonth = (int) now()->month;
    $this->period = PayrollPeriod::forMonth($this->startYear, $this->startMonth);
});

function disHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function disStaff(?User $user = null)
{
    return test()->actingAs($user ?? test()->staffUser)
        ->withSession(['current_business_id' => test()->business->id]);
}

/** Walk a fresh run to Disbursed — nothing may be paid before that. */
function disRun(): PayrollRun
{
    $run = app(CalculatePayroll::class)->handle(test()->period);
    $transition = app(TransitionPayrollRun::class);

    $transition->review($run, test()->hr);
    approveIfPending($run->fresh()->approvalRequest, test()->hr);
    $transition->markApproved($run->fresh(), test()->hr);
    $transition->disburse($run->fresh(), test()->hr);

    return $run->fresh();
}

function disPayslip(PayrollRun $run, ?Employee $employee = null): Payslip
{
    return $run->payslips()->where('employee_id', ($employee ?? test()->employee)->id)->sole();
}

function disLoan(float $principal = 60000, float $installment = 5000): Loan
{
    $loan = app(RequestLoan::class)->handle(
        test()->employee, LoanType::Loan, $principal, $installment,
        test()->startYear, test()->startMonth, 'Family medical expenses', test()->hr,
    );
    approveIfPending($loan->approvalRequest, test()->hr);

    return app(DisburseLoan::class)->handle($loan);
}

// --- SAL-08: the payment method now on the employee ------------------------------

it('asks only for the account details the employee\'s own payment method needs', function () {
    // Bank transfer with no account number is a real problem...
    $this->employee->update(['bank_account_number' => null]);
    $report = app(ValidatePayrollRun::class)->handle($this->period);

    expect(implode(' ', collect($report['employees'])->pluck('issues')->flatten()->all()))
        ->toContain('Bank transfer');

    // ...while cash needs nothing stored at all, and the old blanket check
    // would have blocked this employee for having no account anywhere.
    expect($report['issue_count'])->toBe(1);
});

// --- DIS-01: batches, one per method ---------------------------------------------

it('splits a disbursed run into one batch per payment method', function () {
    $run = disRun();

    $batches = app(CreateDisbursementBatches::class)->handle($run, $this->hr);

    expect($batches)->toHaveCount(2);
    expect($batches->pluck('method')->map->value->sort()->values()->all())
        ->toBe(['bank_transfer', 'cash']);
    expect($batches->firstWhere('method', SalaryPaymentMethod::Cash)->payslips()->count())->toBe(1);
});

it('refuses to batch a payroll that has not been disbursed', function () {
    $run = app(CalculatePayroll::class)->handle($this->period);

    app(CreateDisbursementBatches::class)->handle($run, $this->hr);
})->throws(ValidationException::class);

it('never puts the same payslip in two batches', function () {
    $run = disRun();
    app(CreateDisbursementBatches::class)->handle($run, $this->hr);

    // A second pass finds nothing left to batch rather than duplicating.
    app(CreateDisbursementBatches::class)->handle($run, $this->hr);
})->throws(ValidationException::class);

// --- DIS-01/02: payment events ---------------------------------------------------

it('records nothing as paid before the payroll is disbursed', function () {
    $run = app(CalculatePayroll::class)->handle($this->period);

    app(RecordPayslipPayment::class)->paid(disPayslip($run), $this->hr, 100);
})->throws(ValidationException::class);

it('derives unpaid, partial and paid from the payment events', function () {
    $run = disRun();
    $payslip = disPayslip($run);

    expect($payslip->paymentStatus())->toBe(PayslipPaymentStatus::Unpaid);

    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, 1000);
    expect($payslip->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Partial);

    $payslip = $payslip->fresh();
    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net - 1000);
    expect($payslip->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Paid);
});

it('refuses to pay more than the payslip is worth', function () {
    $run = disRun();
    $payslip = disPayslip($run);

    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net + 1);
})->throws(ValidationException::class);

it('confirms a whole batch by paying every payslip in it', function () {
    $run = disRun();
    $batches = app(CreateDisbursementBatches::class)->handle($run, $this->hr);
    $batch = $batches->firstWhere('method', SalaryPaymentMethod::BankTransfer);

    // ⚠ Confirmation now requires the account the money left from. That is the
    // point of the change, not an inconvenience of it: a payment nobody can
    // trace to an account cannot appear in a cash book.
    $paid = app(ConfirmDisbursementBatch::class)->handle($batch, $this->hr, null, 'ADV-9911', disAccount());

    expect($paid)->toBe(1);
    expect($batch->fresh()->isConfirmed())->toBeTrue();
    expect($batch->fresh()->advice_reference)->toBe('ADV-9911');
    expect(disPayslip($run)->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Paid);
});

it('does not pay an already-settled payslip a second time when the batch is confirmed', function () {
    $run = disRun();
    $batch = app(CreateDisbursementBatches::class)->handle($run, $this->hr)
        ->firstWhere('method', SalaryPaymentMethod::BankTransfer);

    $payslip = disPayslip($run);
    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net);

    expect(app(ConfirmDisbursementBatch::class)->handle($batch, $this->hr, moneyAccount: disAccount()))->toBe(0);
    expect($payslip->fresh()->paidTotal())->toBe(round((float) $payslip->net, 2));
});

it('keeps a failed attempt on record with its reason', function () {
    $run = disRun();
    $payslip = disPayslip($run);

    app(RecordPayslipPayment::class)->failed($payslip, $this->hr, 'Account closed');

    $payslip = $payslip->fresh();
    expect($payslip->paymentStatus())->toBe(PayslipPaymentStatus::Unpaid);
    expect($payslip->currentFailure()?->failure_reason)->toBe('Account closed');
    expect($payslip->paidTotal())->toBe(0.0);
});

it('reverses a payment that came back, without editing the original', function () {
    $run = disRun();
    $payslip = disPayslip($run);

    $payment = app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net);
    app(RecordPayslipPayment::class)->reverse($payment, $this->hr, 'Returned by the bank');

    expect($payment->fresh()->amount)->toEqual($payment->amount);
    expect($payslip->fresh()->paidTotal())->toBe(0.0);
    expect($payslip->fresh()->payments()->count())->toBe(2);
});

// --- Correction C9c: a failed payment reverses the 3e/3d ledgers -----------------

it('reverses the loan installment when the salary payment fails', function () {
    $loan = disLoan();
    $run = disRun();
    $payslip = disPayslip($run);

    // The Disbursed transition collected installment 1.
    expect($loan->fresh()->outstanding())->toBe(55000.0);
    expect($loan->installments()->where('sequence', 1)->sole()->status)->toBe(LoanInstallmentStatus::Paid);

    app(RecordPayslipPayment::class)->failed($payslip, $this->hr, 'Account closed');

    // Nothing reached the employee, so nothing was collected from them.
    expect($loan->fresh()->outstanding())->toBe(60000.0);
    expect($loan->installments()->where('sequence', 1)->sole()->status)->toBe(LoanInstallmentStatus::Scheduled);
    // Append-only: the repayment is still there, with a reversal beside it.
    expect($loan->ledgerEntries()->count())->toBe(3);
});

it('collects the installment again when the payment succeeds on retry', function () {
    $loan = disLoan();
    $run = disRun();
    $payslip = disPayslip($run);

    app(RecordPayslipPayment::class)->failed($payslip, $this->hr, 'Account closed');
    expect($loan->fresh()->outstanding())->toBe(60000.0);

    $payslip = $payslip->fresh();
    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net);

    expect($loan->fresh()->outstanding())->toBe(55000.0);
    expect($loan->installments()->where('sequence', 1)->sole()->status)->toBe(LoanInstallmentStatus::Paid);
});

it('leaves the ledgers alone on a partial payment, which DIS-02 hands to a human', function () {
    $loan = disLoan();
    $run = disRun();
    $payslip = disPayslip($run);

    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, 1000);

    expect($payslip->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Partial);
    // Apportioning a shortfall across loan recovery and net pay is a rule
    // nobody has stated, so nothing is invented here.
    expect($loan->fresh()->outstanding())->toBe(55000.0);
    expect($loan->ledgerEntries()->count())->toBe(2);
});

it('reopens a loan that was closed by the repayment now reversed', function () {
    // One installment covering the whole principal: the Disbursed transition
    // settles the loan outright.
    $loan = disLoan(5000, 5000);
    $run = disRun();
    $payslip = disPayslip($run);

    expect($loan->fresh()->status)->toBe(LoanStatus::Settled);

    app(RecordPayslipPayment::class)->failed($payslip, $this->hr, 'Account closed');

    expect($loan->fresh()->status)->toBe(LoanStatus::Active);
    expect($loan->fresh()->outstanding())->toBe(5000.0);
});

it('reconciles the same way however many payment events land', function () {
    $loan = disLoan();
    $run = disRun();

    foreach (['first attempt', 'second attempt', 'third attempt'] as $reason) {
        app(RecordPayslipPayment::class)->failed(disPayslip($run)->fresh(), $this->hr, $reason);
    }

    // Three failures, one reversal — the reconciler asks whether the payslip is
    // settled, not what the last event was.
    expect($loan->fresh()->outstanding())->toBe(60000.0);
    expect($loan->ledgerEntries()->count())->toBe(3);
});

// --- SAL-07 / C9b: the payslip PDF ------------------------------------------------

it('builds the payslip password from the date of birth alone', function () {
    // Shortened by Joy on 1 September 2026: "payslip password is very long,
    // make it very short like 20121992". ⚠ Little was given up - the employee
    // code is printed on the payslip itself and runs in sequence, so it added
    // characters rather than secrecy. The date of birth was always the only
    // private half.
    expect(PayslipPassword::for($this->employee))->toBe('31121995');
});

it('has no password at all for an employee with no date of birth', function () {
    // ⛔ Never a weaker fallback. An export that claims protection it does not
    // have is worse than one that will not open.
    $this->employee->update(['date_of_birth' => null]);

    expect(PayslipPassword::for($this->employee->fresh()))->toBeNull()
        ->and(PayslipPassword::missingReason($this->employee->fresh()))->toContain('date of birth');
});

it('renders a protected PDF', function () {
    $run = disRun();

    $pdf = app(PayslipPdf::class)->render(disPayslip($run), $this->hr, protected: true);

    expect(substr($pdf, 0, 4))->toBe('%PDF');
    // An encrypted PDF declares its encryption dictionary; an unprotected one
    // has no /Encrypt at all.
    expect($pdf)->toContain('/Encrypt');
});

it('serves the protected PDF over HTTP, not an empty response', function () {
    $run = disRun();

    $response = disHr()->get(route('hrm.payslips.pdf', disPayslip($run)));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    expect($response->getContent())->toContain('/Encrypt');
});

it('refuses the protected PDF rather than weakening it when no password can be formed', function () {
    $this->employee->update(['date_of_birth' => null]);
    $run = disRun();

    app(PayslipPdf::class)->render(disPayslip($run), $this->hr, protected: true);
})->throws(ValidationException::class);

// --- SAL-07 / C5-ACL-02: who may see a payslip ------------------------------------

it('lets an employee open their own payslip once the payroll is approved', function () {
    $run = disRun();

    disStaff()->get(route('hrm.payslips.show', disPayslip($run)))->assertOk();
    disStaff()->get(route('hrm.my-payslips'))->assertOk();
});

it('hides a payslip from an employee while the payroll is still being calculated', function () {
    $run = app(CalculatePayroll::class)->handle($this->period);

    disStaff()->get(route('hrm.payslips.show', disPayslip($run)))->assertForbidden();
});

it('refuses a user who is neither HR nor the employee', function () {
    $run = disRun();

    disStaff($this->otherUser)->get(route('hrm.payslips.show', disPayslip($run)))->assertForbidden();
    disStaff($this->otherUser)->get(route('hrm.payslips.pdf', disPayslip($run)))->assertForbidden();
});

it('keeps the disbursement screen behind hrm.payroll.manage', function () {
    $run = disRun();

    disStaff()->get(route('hrm.payroll.disbursement', $run))->assertForbidden();
    disHr()->get(route('hrm.payroll.disbursement', $run))->assertOk();
});

// --- DIS-04: email -----------------------------------------------------------------

it('queues one job per payslip that has somewhere to go, and names the rest', function () {
    Queue::fake();
    $run = disRun();

    // ⚠ Karim has a login; Rahim, the cash employee, has neither a login nor a
    // personal address. This assertion used to expect two jobs, one of which
    // could only ever fail with "no email address on record" - a fact HR then
    // had to go looking for. The skip is now reported instead.
    disHr()->post(route('hrm.payroll.email-payslips', $run))
        ->assertRedirect()
        ->assertSessionHas('warning', fn ($message) => str_contains($message, '1 payslip(s) queued')
            && str_contains($message, 'Rahim Uddin'));

    Queue::assertPushed(SendPayslipEmail::class, 1);
});

// --- DIS-06 / C9d: the register, and DIS-05's accounting export ---------------------

it('exports a register of our own columns and does not call it a bank advice', function () {
    $run = disRun();
    $batch = app(CreateDisbursementBatches::class)->handle($run, $this->hr)
        ->firstWhere('method', SalaryPaymentMethod::BankTransfer);

    $format = app(DisbursementFormatRegistry::class)->get('generic_csv');
    $csv = $format->render($batch, app(DisbursementRegister::class)->rowsFor($batch));

    expect($csv)->toContain('BDG-000001')->toContain('Brac Bank')->toContain('TOTAL');
    expect($format->description())->toContain('Not a bank advice');
    // One format registered, deliberately — no bank layout has been invented.
    expect(app(DisbursementFormatRegistry::class)->all())->toHaveCount(1);
});

it('leaves a settled payslip off the register', function () {
    $run = disRun();
    $batch = app(CreateDisbursementBatches::class)->handle($run, $this->hr)
        ->firstWhere('method', SalaryPaymentMethod::BankTransfer);

    $payslip = disPayslip($run);
    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net);

    expect(app(DisbursementRegister::class)->rowsFor($batch->fresh()))->toHaveCount(0);
});

it('exports the payroll for accounting, in summary and in detail', function () {
    $run = disRun();
    $export = app(AccountingExport::class);

    $summary = $export->render($run, AccountingExport::SUMMARY);
    expect($summary)->toContain('Component code')->toContain('Net payable');

    $detail = $export->render($run, AccountingExport::DETAIL);
    expect($detail)->toContain('BDG-000001')->toContain('Head Office')->toContain('Basis');
});

// --- The screens render ------------------------------------------------------------

it('renders the disbursement screen with its batches and payslips', function () {
    $run = disRun();
    app(CreateDisbursementBatches::class)->handle($run, $this->hr);

    disHr()->get(route('hrm.payroll.disbursement', $run))
        ->assertInertia(fn ($page) => $page
            ->component('hrm/payroll/disbursement')
            ->has('batches', 2)
            ->has('payslips', 2)
            ->where('run.is_disbursed', true));
});

it('renders one payslip with its stored derivation', function () {
    $run = disRun();

    disHr()->get(route('hrm.payslips.show', disPayslip($run)))
        ->assertInertia(fn ($page) => $page
            ->component('hrm/payroll/payslip')
            ->has('document.earnings')
            ->has('document.attendance')
            ->where('document.employee.code', 'BDG-000001'));
});

it('lists an employee their own payslips and nobody else\'s', function () {
    $run = disRun();

    disStaff()->get(route('hrm.my-payslips'))
        ->assertInertia(fn ($page) => $page
            ->component('hrm/payroll/my-payslips')
            ->has('payslips', 1)
            ->where('employee.employee_code', 'BDG-000001'));
});

it('confirms a batch through the real HTTP route', function () {
    $run = disRun();
    app(CreateDisbursementBatches::class)->handle($run, $this->hr);
    $batch = DisbursementBatch::where('method', SalaryPaymentMethod::Cash)->sole();

    disHr()->post(route('hrm.batches.confirm', $batch), [
        // Required since 12 Oct 2026: the day the money actually left.
        'paid_on' => now()->toDateString(),
        'advice_reference' => 'CASH-01',
        'money_account_id' => disAccount()->id,
    ])
        ->assertRedirect();

    expect(disPayslip($run, $this->cashEmployee)->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Paid);
});

it('keeps a locked payroll payable, since locking follows disbursement', function () {
    $run = disRun();
    app(TransitionPayrollRun::class)->lock($run, $this->hr);

    expect($run->fresh()->status)->toBe(PayrollStatus::Locked);

    $payslip = disPayslip($run->fresh());
    app(RecordPayslipPayment::class)->paid($payslip, $this->hr, (float) $payslip->net);

    expect($payslip->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Paid);
});

/**
 * The account salaries are paid from. ⚠ Created on demand rather than in
 * beforeEach so it is obvious in each test that a payment now names one.
 */
function disAccount(): MoneyAccount
{
    return MoneyAccount::firstOrCreate(
        ['name' => 'Payroll account'],
        ['type' => MoneyAccountType::Bank, 'account_number' => '1501202536477001'],
    );
}

// --- Can the screen actually do what the route demands? --------------------------

it('gives the disbursement screen the accounts it has to ask about', function () {
    $run = disRun();

    disHr()->get(route('hrm.payroll.disbursement', $run))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/disbursement')
            ->has('moneyAccounts'));
});

// The compact batch row (15 Oct 2026) names who is in the batch and, once
// confirmed, which account paid, so a wrong account is visible where it was
// chosen rather than only in the books.
it('tells the batch row who is in it and which account paid it', function () {
    $run = disRun();
    app(CreateDisbursementBatches::class)->handle($run, $this->hr);
    $batch = DisbursementBatch::where('method', SalaryPaymentMethod::Cash)->sole();
    // One batch per method, in id order: find where the cash one sits.
    $at = DisbursementBatch::where('payroll_run_id', $run->id)->orderBy('id')->pluck('id')->search($batch->id);

    disHr()->get(route('hrm.payroll.disbursement', $run))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("batches.$at.account", null)
            ->where("batches.$at.people.0", $this->cashEmployee->fullName()));

    disHr()->post(route('hrm.batches.confirm', $batch), [
        'paid_on' => now()->toDateString(),
        'money_account_id' => disAccount()->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    disHr()->get(route('hrm.payroll.disbursement', $run))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("batches.$at.account", 'Payroll account'));
});

it('sends the account from the screen, not only from a test', function () {
    // ⚠⚠ THE TRAP THIS REPO KEEPS FALLING INTO. The HTTP test above passes
    // `money_account_id` by hand, so it proves the route works and says nothing
    // about whether anything ever sends it. The screen sent only the date and
    // the advice reference, the route has always REQUIRED the account, and so
    // "Confirm batch paid" failed validation every single time - in silence.
    // August's salary could not be recorded as paid by any route at all.
    $source = file_get_contents(resource_path('js/pages/hrm/payroll/disbursement.tsx'));

    $confirm = substr($source, strpos($source, "route('hrm.batches.confirm'"), 400);
    expect($confirm)->toContain('money_account_id');

    $payment = substr($source, strpos($source, "route('hrm.payslips.payments.store'"), 400);
    expect($payment)->toContain('money_account_id');
});

// --- Audit 13 Oct 2026: locked, then checked ---------------------------------------

it('reads what is already paid afresh rather than from the copy the page loaded', function () {
    $run = disRun();
    // Loaded with its payments BEFORE anyone paid - the stale copy a second
    // clerk would be holding.
    $stale = disPayslip($run)->load('payments');

    app(RecordPayslipPayment::class)->paid(disPayslip($run), $this->hr, (float) $stale->net);

    expect(fn () => app(RecordPayslipPayment::class)->paid($stale, $this->hr, (float) $stale->net))
        ->toThrow(ValidationException::class, 'more than');
    expect(disPayslip($run)->paidTotal())->toBe(round((float) $stale->net, 2));
});

it('refuses to confirm a batch a second time from a stale copy', function () {
    $run = disRun();
    $batch = app(CreateDisbursementBatches::class)->handle($run, $this->hr)
        ->firstWhere('method', SalaryPaymentMethod::BankTransfer);
    $stale = DisbursementBatch::findOrFail($batch->id);

    app(ConfirmDisbursementBatch::class)->handle($batch, $this->hr, null, 'ADV-1', disAccount());
    // The guard before the transaction passed on the stale copy; the one under
    // the lock is what refuses.
    $stale->setAttribute('confirmed_at', null);

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($stale, $this->hr, null, 'ADV-2', disAccount()))
        ->toThrow(ValidationException::class, 'already been confirmed');
});

it('requires the paid date on an individual payment and refuses a future one', function () {
    // ⚠ Audit 13 Oct 2026: a missing date silently became today. Same rule as
    // the batch confirmation since 12 Oct.
    $run = disRun();
    $payslip = disPayslip($run);
    $payload = ['amount' => 100, 'money_account_id' => disAccount()->id];

    disHr()->post(route('hrm.payslips.payments.store', $payslip), $payload)->assertSessionHasErrors('paid_on');
    disHr()->post(route('hrm.payslips.payments.store', $payslip), [...$payload, 'paid_on' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors('paid_on');

    expect($payslip->fresh()->paidTotal())->toBe(0.0);
});

// --- HRM-002 (audit 14 Oct 2026): an unlocked month is paid once ----------------
//
// After a WFL-08 unlock the new version's payslips are new rows with no payments
// of their own, so the disbursement screen showed the full net as outstanding and
// the payment guard let it be paid a second time. And PostLoanRepayments had
// already marked the month's installment Paid, so the new version was calculated
// WITHOUT the loan line and came out higher by the installment. Both are wrong
// money; these tests pin the fix: payments carry across versions of one month,
// and an installment recovered by the earlier version stays on the payslip.

/** v1 disbursed, paid in full and locked; then override-unlocked to a v2 Draft. */
function paidLockedThenUnlocked(): array
{
    $v1 = disRun();
    foreach ($v1->payslips as $payslip) {
        app(RecordPayslipPayment::class)->paid($payslip, test()->hr, (float) $payslip->net);
    }
    app(TransitionPayrollRun::class)->lock($v1->fresh(), test()->hr);

    $admin = User::factory()->create(['is_super_admin' => true]);
    app(RequestPayrollUnlock::class)
        ->handle($v1->fresh(), $admin, 'Bank file corrected', true, 'Directors agreed by phone');

    return [$v1->fresh(), PayrollRun::query()->where('payroll_period_id', test()->period->id)->where('version', 2)->sole()];
}

function walkToDisbursed(PayrollRun $run): PayrollRun
{
    $run = app(CalculatePayroll::class)->handle(test()->period);
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, test()->hr);
    approveIfPending($run->fresh()->approvalRequest, test()->hr);
    $transition->markApproved($run->fresh(), test()->hr);
    $transition->disburse($run->fresh(), test()->hr);

    return $run->fresh();
}

it('shows nothing outstanding on an unlocked version whose figures did not change, and refuses a second payment', function () {
    [$v1, $v2] = paidLockedThenUnlocked();
    $v2 = walkToDisbursed($v2);

    expect($v2->version)->toBe(2);

    $old = disPayslip($v1);
    $new = disPayslip($v2);

    expect((float) $new->net)->toBe((float) $old->net)
        ->and($new->outstanding())->toBe(0.0)
        ->and($new->paymentStatus())->toBe(PayslipPaymentStatus::Paid);

    expect(fn () => app(RecordPayslipPayment::class)->paid($new, $this->hr, (float) $new->net))
        ->toThrow(ValidationException::class);

    // The disbursement screen agrees with the guard.
    disHr()->get(route('hrm.payroll.disbursement', $v2))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payslips.0.outstanding', 0)
            ->where('payslips.0.payment_status', PayslipPaymentStatus::Paid->value));

    // And a batch confirmed on v2 pays nobody.
    $batches = app(CreateDisbursementBatches::class)->handle($v2, $this->hr);
    $money = MoneyAccount::create(['name' => 'Bank', 'type' => MoneyAccountType::Bank, 'is_active' => true]);
    foreach ($batches as $batch) {
        expect(app(ConfirmDisbursementBatch::class)->outstandingIn($batch))->toHaveCount(0);
    }
    expect(PayslipPayment::query()->where('payslip_id', $new->id)->count())->toBe(0);

    // The unpaid salary report does not list v2 as owed either.
    $rows = iterator_to_array(app(UnpaidSalaryReport::class)->rows(new ReportParameters(['run_id' => $v2->id])));
    expect($rows)->toBe([]);
});

it('pays only the difference when the unlocked version comes out higher', function () {
    [$v1, $v2] = paidLockedThenUnlocked();

    PayrollAdjustment::once('test:hrm-002-arrear', [
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'payroll_period_id' => $this->period->id,
        'type' => PayrollAdjustmentType::Arrear,
        'amount' => '1500.00',
        'reason' => 'Missed arrear found after the lock',
        'requested_by' => $this->hr->id,
    ]);

    $v2 = walkToDisbursed($v2);
    $old = disPayslip($v1);
    $new = disPayslip($v2);

    expect(round((float) $new->net - (float) $old->net, 2))->toBe(1500.0)
        ->and($new->outstanding())->toBe(1500.0)
        ->and($new->paymentStatus())->toBe(PayslipPaymentStatus::Partial);

    expect(fn () => app(RecordPayslipPayment::class)->paid($new, $this->hr, 1500.01))
        ->toThrow(ValidationException::class);

    app(RecordPayslipPayment::class)->paid($new->fresh(), $this->hr, 1500.0);

    expect($new->fresh()->outstanding())->toBe(0.0)
        ->and($new->fresh()->paymentStatus())->toBe(PayslipPaymentStatus::Paid);
});

it('keeps an installment the locked version recovered on the unlocked version, and never recovers it twice', function () {
    $loan = disLoan();
    [$v1, $v2] = paidLockedThenUnlocked();

    expect($loan->fresh()->outstanding())->toBe(55000.0);
    $entriesBefore = $loan->ledgerEntries()->count();

    $v2 = walkToDisbursed($v2);
    $old = disPayslip($v1);
    $new = disPayslip($v2);

    // The deduction is still on the payslip, so the net is not inflated by it.
    expect($new->lines()->where('code', 'loan_repayment')->sum('amount'))->toEqual(5000)
        ->and((float) $new->net)->toBe((float) $old->net)
        ->and($new->outstanding())->toBe(0.0);

    // Recovered once: same balance, same installment, no new ledger rows.
    expect($loan->fresh()->outstanding())->toBe(55000.0)
        ->and($loan->ledgerEntries()->count())->toBe($entriesBefore)
        ->and($loan->installments()->where('sequence', 1)->sole()->status)->toBe(LoanInstallmentStatus::Paid)
        ->and($loan->installments()->where('sequence', 2)->sole()->status)->toBe(LoanInstallmentStatus::Scheduled);
});
