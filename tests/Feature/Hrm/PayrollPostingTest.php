<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\ConfirmDisbursementBatch;
use App\Modules\Hrm\Actions\RecordPayslipPayment;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Models\PayslipPayment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 of Accounting: payroll posts to the ledger.
 *
 * ⚠⚠ AUTOMATIC, FROM THE BUSINESS EVENT, ALWAYS. Joy's principle of 2026-08-03:
 * users never create accounting entries for payroll by hand. Every wage figure
 * in the accounts therefore has exactly one origin, which is what lets a
 * reconciliation be conclusive rather than suggestive.
 *
 * See ACCOUNTING-PHASE3-INTEGRATION.md §3.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Wage Co', 'slug' => 'wage-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 7,
        'start_date' => '2026-06-24', 'end_date' => '2026-07-23', 'divisor_days' => 30,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    /**
     * A run with one payslip: 30,000 basic, 1,000 income tax, 2,000 loan
     * recovery, 500 absence. Net 26,500.
     */
    $this->buildRun = function (?array $lines = null, string $net = '26500.00') {
        $run = PayrollRun::create([
            'business_id' => $this->business->id,
            'payroll_period_id' => $this->period->id,
            'version' => 1,
            'status' => PayrollStatus::Reviewed,
        ]);

        $payslip = Payslip::create([
            'business_id' => $this->business->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'gross_salary' => '30000.00',
            'total_earnings' => '30000.00',
            'total_deductions' => '3500.00',
            'net' => $net,
        ]);

        foreach ($lines ?? [
            ['basic', 'earning', '30000.00'],
            ['income_tax', 'deduction', '1000.00'],
            ['loan_repayment', 'deduction', '2000.00'],
            ['absent', 'deduction', '500.00'],
        ] as $index => [$code, $type, $amount]) {
            PayslipLine::create([
                'payslip_id' => $payslip->id,
                'code' => $code,
                'label' => $code,
                'type' => $type,
                'amount' => $amount,
                'formula' => 'fixed',
                'source' => 'test',
                'sequence' => $index + 1,
            ]);
        }

        return $run->fresh();
    };

    $this->buildBatch = function (PayrollRun $run, string $method) {
        $batch = DisbursementBatch::create([
            'business_id' => $this->business->id,
            'payroll_run_id' => $run->id,
            'reference' => 'DIS-202607-V1-'.strtoupper($method),
            'method' => $method,
            'created_by' => $this->user->id,
        ]);

        $batch->payslips()->attach($run->payslips()->pluck('id'));

        return $batch;
    };
});

// ─── The accrual ────────────────────────────────────────────────────────────

it('posts the wage bill when the run is approved', function () {
    $run = ($this->buildRun)();

    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    $journal = Journal::first();

    expect($journal)->not->toBeNull()
        ->and($journal->source->is($run))->toBeTrue()
        ->and($journal->balance()->isZero())->toBeTrue();

    $salaries = Account::bySlug('salaries_expense');
    $payable = Account::bySlug('salaries_payable');
    $deductions = Account::bySlug('employee_deductions_payable');
    $advances = Account::bySlug('employee_advances');

    $amountOn = fn (Account $account) => (int) JournalLine::where('journal_id', $journal->id)
        ->where('account_id', $account->id)->sum('amount_minor');

    // ⚠⚠ THE LINE THAT IS EASY TO GET WRONG. 30,000 of basic less 500 of
    // absence is 29,500 of wage COST - the absence credits the expense back
    // rather than creating a liability. Filing it as a payable would inflate
    // both the cost and the liability by 500 and still balance perfectly.
    expect($amountOn($salaries))->toBe(2950000)
        ->and($amountOn($payable))->toBe(-2650000)
        ->and($amountOn($deductions))->toBe(-100000)
        // Recovering a loan reduces an asset; it is not income (C-INT-3).
        ->and($amountOn($advances))->toBe(-200000);
});

it('dates the accrual to the period the work was done in', function () {
    $run = ($this->buildRun)();

    // ⚠⚠ Approved on 3 August, but this is July's payroll. Accrual basis means
    // the cost belongs to July; dating it on the approval would move a month of
    // wages into the wrong month, and every month's profit would be wrong by
    // one month's wages until the pattern happened to settle.
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    expect(Journal::first()->journal_date->toDateString())->toBe('2026-07-23');
});

it('names the employee on the payable so the ledger can say who is owed', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    $payable = Account::bySlug('salaries_payable');
    $line = JournalLine::where('account_id', $payable->id)->first();

    // Wages Payable is a subsidiary ledger: "who are we still owing" has to be
    // answerable without reading payslips.
    expect($line->party->is($this->employee))->toBeTrue()
        ->and($line->amount()->absolute()->format())->toBe('26,500.00');
});

it('posts a run once, however many times it is approved', function () {
    $run = ($this->buildRun)();

    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    app(TransitionPayrollRun::class)->markApproved($run->fresh(), $this->user);

    // ⚠ A retried job or a double click must not double the wage bill. The
    // journal's own source is the check, so there is no "posted" flag to drift.
    expect(Journal::count())->toBe(1);
});

it('refuses to approve at all when a component is unmapped', function () {
    // A brand new deduction nobody has filed yet.
    $run = ($this->buildRun)([
        ['basic', 'earning', '30000.00'],
        ['union_dues', 'deduction', '500.00'],
    ], '29500.00');

    AccountMapping::where('key', 'payroll.deduction.default')->delete();

    // ⚠⚠ The approval FAILS rather than posting an incomplete journal. A wage
    // bill missing one component is not a smaller wage bill, it is a wrong one.
    expect(fn () => app(TransitionPayrollRun::class)->markApproved($run, $this->user))
        ->toThrow(MappingNotFound::class);

    // ⚠ And the status did not move either, because the posting shares the
    // approval's transaction. A run that is approved but unposted is a state
    // nobody could reconcile.
    expect($run->fresh()->status)->toBe(PayrollStatus::Reviewed)
        ->and(Journal::count())->toBe(0);
});

// ─── Cancelling and reopening ───────────────────────────────────────────────

it('lets a business that has not switched accounting on run payroll exactly as before', function () {
    // ⚠⚠ THE 52-TEST LESSON. When this integration first landed it made
    // Accounting a hard dependency of APPROVING A PAYROLL, and every existing
    // payroll test failed. That was the code saying something true: a business
    // which has not set up its ledger must still be able to pay its people, and
    // an accounting feature must never be able to stop a live payroll.
    AccountMapping::query()->delete();

    $run = ($this->buildRun)();

    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    expect($run->fresh()->status)->toBe(PayrollStatus::Approved)
        ->and(Journal::count())->toBe(0);
});

it('still refuses when accounting IS on and one component is unmapped', function () {
    // ⚠ The other half of the same distinction. "Not configured" is a
    // legitimate state; "configured but incomplete" is a defect, and a wage
    // bill missing one component is not a smaller wage bill, it is a wrong one.
    AccountMapping::where('key', 'payroll.deduction.default')->delete();
    AccountMapping::where('key', 'payroll.deduction.income_tax')->delete();

    $run = ($this->buildRun)();

    expect(fn () => app(TransitionPayrollRun::class)->markApproved($run, $this->user))
        ->toThrow(MappingNotFound::class);

    expect($run->fresh()->status)->toBe(PayrollStatus::Reviewed);
});

it('cancels an unposted run without touching the ledger', function () {
    // ⚠⚠ WORTH KNOWING: `transitionableTo()` does NOT allow Approved to reach
    // Cancelled. An approved run goes to Disbursed or is reopened, so cancelling
    // only ever reaches a run that was never approved and therefore never
    // posted. The reversal call in `cancel()` is deliberately defensive, and
    // this test pins the real behaviour rather than the behaviour I first
    // assumed when writing it.
    $run = ($this->buildRun)();

    app(TransitionPayrollRun::class)->cancel($run, $this->user, 'Wrong attendance');

    expect($run->fresh()->status)->toBe(PayrollStatus::Cancelled)
        ->and(Journal::count())->toBe(0);
});

it('refuses to cancel an approved run at all', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    // The cost stays in the books because the run stays approved. Reopening is
    // the supported route out, and that one does reverse.
    expect(fn () => app(TransitionPayrollRun::class)->cancel($run->fresh(), $this->user, 'Too late'))
        ->toThrow(ValidationException::class);

    expect(Journal::count())->toBe(1);
});

it('takes the superseded version out when a run is reopened', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);

    $next = app(TransitionPayrollRun::class)->reopen($run->fresh(), $this->user, 'Recalculating');

    // ⚠⚠ Without this the new version's accrual would ADD to the old one and
    // the wage bill would double every time somebody reopened a payroll - which
    // would look like a rounding problem for exactly one month and then like
    // something worse.
    expect($next->version)->toBe(2)
        ->and(Journal::count())->toBe(2)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ─── The payment ────────────────────────────────────────────────────────────

it('records where the salary money actually went', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    // Payment can only follow disbursement - the domain already refuses to
    // record a payslip as paid before then.
    $run = app(TransitionPayrollRun::class)->disburse($run->fresh(), $this->user);

    $bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $batch = ($this->buildBatch)($run, 'bank_transfer');

    app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, Carbon::parse('2026-08-01'), null, $bank);

    // ⚠⚠ The journal now hangs off the PAYSLIP PAYMENT, not the batch. Posting
    // moved down to the single payment so that confirming a batch and paying
    // one person by hand go through the same mechanism - before this, the
    // individual route moved money and told the ledger nothing.
    $payment = Journal::where('source_type', (new PayslipPayment)->getMorphClass())->first();

    expect($payment)->not->toBeNull()
        ->and($payment->balance()->isZero())->toBeTrue()
        ->and(Journal::where('source_type', $batch->getMorphClass())->count())->toBe(0);

    $payable = Account::bySlug('salaries_payable');
    $cashAtBank = Account::bySlug('cash_at_bank');

    // ⚠ The payable the approval accrued is cleared, per employee, and the
    // credit lands on the money account's OWN ledger account rather than a
    // mapping key - a bank account already knows what it is.
    expect((int) JournalLine::where('journal_id', $payment->id)->where('account_id', $payable->id)->sum('amount_minor'))->toBe(2650000)
        ->and((int) JournalLine::where('journal_id', $payment->id)->where('account_id', $cashAtBank->id)->sum('amount_minor'))->toBe(-2650000);

    // ⚠⚠ And the whole point: after accrual and payment, Wages Payable is back
    // to zero. Nothing is owed because everything was paid.
    expect((int) JournalLine::where('account_id', $payable->id)->sum('amount_minor'))->toBe(0);
});

it('leaves the same record whether one person is paid or a whole batch', function () {
    // ⚠⚠ THE POINT OF THIS SLICE. ConfirmDisbursementBatch's docblock has always
    // claimed that "whole batch" and "employee-wise" are the same mechanism at
    // two speeds - but only the batch reached the LEDGER, so paying one person
    // by hand moved money and told the books nothing. The coverage matrix found
    // it.
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    $run = app(TransitionPayrollRun::class)->disburse($run->fresh(), $this->user);

    $bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // Paid one at a time, with no batch confirmation anywhere.
    app(RecordPayslipPayment::class)->paid(
        payslip: $run->payslips()->first(),
        user: $this->user,
        amount: 26500.00,
        moneyAccount: $bank,
    );

    $payable = Account::bySlug('salaries_payable');
    $cashAtBank = Account::bySlug('cash_at_bank');

    // Exactly what the batch route produces: the payable cleared per employee,
    // the bank down by the same amount.
    expect((int) JournalLine::where('account_id', $payable->id)->sum('amount_minor'))->toBe(0)
        ->and((int) JournalLine::where('account_id', $cashAtBank->id)->sum('amount_minor'))->toBe(-2650000);

    $journal = Journal::where('source_type', (new PayslipPayment)->getMorphClass())->first();
    expect($journal)->not->toBeNull()
        ->and($journal->lines->first()->party->is($this->employee))->toBeTrue();
});

it('takes the journal back out when a payment is reversed', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    $run = app(TransitionPayrollRun::class)->disburse($run->fresh(), $this->user);

    $bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $payment = app(RecordPayslipPayment::class)->paid(
        payslip: $run->payslips()->first(),
        user: $this->user,
        amount: 26500.00,
        moneyAccount: $bank,
    );

    app(RecordPayslipPayment::class)->reverse($payment, $this->user, 'Bank rejected the transfer');

    // ⚠ A bounced transfer took no money, so the bank must come back to where
    // it was - and the payable must go back to being owed.
    expect((int) JournalLine::where('account_id', Account::bySlug('cash_at_bank')->id)->sum('amount_minor'))->toBe(0)
        ->and((int) JournalLine::where('account_id', Account::bySlug('salaries_payable')->id)->sum('amount_minor'))->toBe(-2650000);
});

it('refuses to confirm from an account that has no ledger account', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    $run = app(TransitionPayrollRun::class)->disburse($run->fresh(), $this->user);

    $unmapped = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Petty cash tin',
        'type' => MoneyAccountType::Cash,
    ]);

    $batch = ($this->buildBatch)($run, 'cash');

    // ⚠ Setup somebody did not finish. Failing loudly is what makes them finish
    // it; posting the credit somewhere plausible is what makes a cash book wrong
    // in a way nobody finds.
    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, null, null, $unmapped))
        ->toThrow(MappingNotFound::class);

    expect($batch->fresh()->confirmed_at)->toBeNull();
});

// ─── The books still add up ─────────────────────────────────────────────────

it('leaves the ledger consistent and balancing', function () {
    $run = ($this->buildRun)();
    app(TransitionPayrollRun::class)->markApproved($run, $this->user);
    // Payment can only follow disbursement - the domain already refuses to
    // record a payslip as paid before then.
    $run = app(TransitionPayrollRun::class)->disburse($run->fresh(), $this->user);

    $bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $batch = ($this->buildBatch)($run, 'bank_transfer');
    app(ConfirmDisbursementBatch::class)->handle($batch, $this->user, Carbon::parse('2026-08-01'), null, $bank);

    $trialBalance = TrialBalance::between(Carbon::parse('2026-07-01'), Carbon::parse('2026-08-31'));
    expect($trialBalance->balances())->toBeTrue();

    $result = LedgerIntegrity::verifyAllBusinesses();
    expect($result['problems'])->toBeEmpty();
});
