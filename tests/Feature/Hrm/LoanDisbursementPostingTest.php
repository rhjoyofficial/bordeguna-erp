<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\SettleLoan;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Loan;
use Illuminate\Support\Carbon;

/**
 * Handing a staff loan over - the mirror of the recovery that already posts.
 *
 * ⚠ I had recorded this as blocked on a decision from Joy: which account funds a
 * staff loan. It was not blocked. The answer is the one payroll already uses -
 * the person disbursing chooses at the time - so there was never a default to
 * invent, only a field to add. "Needs a business decision" and "needs a field"
 * look identical until you ask what the user would actually do.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Lend Co', 'slug' => 'lend-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000012',
        'first_name' => 'Musa', 'last_name' => 'Islam',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->loan = Loan::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'reference' => 'LOAN-0001',
        'type' => LoanType::Advance,
        'status' => LoanStatus::Pending,
        'principal' => '20000.00',
        'installment_amount' => '5000.00',
        'installment_count' => 4,
        'start_year' => 2026,
        'start_month' => 9,
        'reason' => 'Medical',
        'requested_by' => $this->user->id,
    ]);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

it('records the loan as an asset and takes the money out of the bank', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    // ⚠ Dr Employee Advances, Cr the bank. The employee now owes 20,000 and the
    // bank is down by 20,000 - which is what a loan IS, stated in the books.
    expect(($this->balanceOn)('employee_advances'))->toBe(2000000)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-2000000);

    $journal = Journal::first();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and($journal->source->is($this->loan))->toBeTrue()
        ->and($journal->lines->first()->party->is($this->employee))->toBeTrue()
        ->and($this->loan->fresh()->money_account_id)->toBe($this->bank->id);
});

it('posts a loan once, however many times disbursement is attempted', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);
    app(DisburseLoan::class)->handle($this->loan->fresh(), $this->bank, $this->user);

    expect(Journal::count())->toBe(1)
        ->and(($this->balanceOn)('employee_advances'))->toBe(2000000);
});

it('refuses to disburse without saying which account the money came from', function () {
    // ⚠⚠ Once accounting is on, this is the one question the screen has to ask.
    // It is a fact, not a default: the person handing the money over knows which
    // account it left, and nothing else in the system does.
    expect(fn () => app(DisburseLoan::class)->handle($this->loan, null, $this->user))
        ->toThrow(MappingNotFound::class);

    expect(Journal::count())->toBe(0);
});

it('refuses an account with no ledger account behind it', function () {
    $tin = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty cash tin', 'type' => MoneyAccountType::Cash,
    ]);

    expect(fn () => app(DisburseLoan::class)->handle($this->loan, $tin, $this->user))
        ->toThrow(MappingNotFound::class);
});

it('lets a business without accounting lend money exactly as before', function () {
    // ⚠ The same on-ramp as every other integration. An accounting feature must
    // never be able to stop an operational process.
    AccountMapping::query()->delete();

    $loan = app(DisburseLoan::class)->handle($this->loan, null, $this->user);

    expect($loan->status)->toBe(LoanStatus::Active)
        ->and($loan->installments()->count())->toBeGreaterThan(0)
        ->and(Journal::count())->toBe(0);
});

it('meets the recovery on the same account', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    // The payroll deduction `loan_repayment` credits this same account, so
    // disbursement and recovery are two sides of one balance rather than two
    // separate stories.
    expect(AccountMapping::resolve('payroll.employee_advances', Carbon::today())->id)
        ->toBe(AccountMapping::resolve('payroll.deduction.loan_repayment', Carbon::today())->id);

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});

it('dates the disbursement on the day the money left, not the day it was entered', function () {
    // Joy, 1 September 2026: three advances handed out on 11, 17 and 23 August
    // were written up on 1 September. Dating them today would put August cash in
    // September and leave the bank book disagreeing with the bank.
    //
    // ⚠ The date here is a back-date relative to this file's frozen clock
    // (2026-08-03), not the real August dates: a journal cannot be posted in the
    // future, so the test crosses a month boundary backwards instead.
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user, '2026-07-28');

    $loan = $this->loan->fresh();

    expect($loan->disbursed_on->toDateString())->toBe('2026-07-28')
        // The loan ledger and the accounting ledger record ONE event. If they
        // date it differently, the two records of the same debt disagree.
        ->and($loan->ledgerEntries()->sole()->occurred_on->toDateString())->toBe('2026-07-28')
        ->and(Journal::first()->journal_date->toDateString())->toBe('2026-07-28');
});

it('still dates a disbursement today when no day is given', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    expect($this->loan->fresh()->disbursed_on->toDateString())->toBe(now()->toDateString());
});

// --- Early settlement posts too (found 3 Oct 2026) ------------------------------------

it('clears the employee balance on 1170 when a loan is settled early', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    // ⚠⚠ Settlement used to write only the HR loan ledger: the loan read
    // "settled" while 1170 still said the employee owed 20,000.
    app(SettleLoan::class)->handle(
        $this->loan->fresh(), 20000, 'Paid back by bank transfer', $this->bank, '2026-08-03', $this->user,
    );

    expect($this->loan->fresh()->status)->toBe(LoanStatus::Settled)
        ->and(($this->balanceOn)('employee_advances'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(0)
        ->and(Journal::count())->toBe(2);

    $settlement = Journal::latest('id')->first();

    expect($settlement->balance()->isZero())->toBeTrue()
        ->and($settlement->lines->firstWhere('amount_minor', '<', 0)->party->is($this->employee))->toBeTrue();
});

it('refuses an early settlement that cannot say where the money came in, and keeps the loan open', function () {
    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    expect(fn () => app(SettleLoan::class)->handle(
        $this->loan->fresh(), 20000, 'Paid back', null, '2026-08-03', $this->user,
    ))->toThrow(MappingNotFound::class);

    expect($this->loan->fresh()->status)->toBe(LoanStatus::Active)
        ->and(Journal::count())->toBe(1);
});

it('keeps 1170 postable even after it was turned into a heading', function () {
    // The 21 Sep state: the payroll mapping still points at a heading.
    Account::bySlug('employee_advances')->update(['is_postable' => false]);

    (include base_path('app/Modules/Accounting/database/migrations/2026_10_03_100000_employee_advances_account_takes_postings_again.php'))->up();

    app(DisburseLoan::class)->handle($this->loan, $this->bank, $this->user);

    expect(($this->balanceOn)('employee_advances'))->toBe(2000000);
});
