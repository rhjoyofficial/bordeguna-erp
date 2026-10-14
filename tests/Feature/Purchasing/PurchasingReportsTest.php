<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Actions\SettleRecurringOccurrence;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Reporting\Definitions\ExpenseRegisterReport;
use App\Modules\Purchasing\Reporting\Definitions\StandingCostsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 1 September 2026: "always export not only csv, pdf, and must printable,
 * also the prepared by, checked by and approve by things need".
 *
 * ⚠⚠ THE POINT OF PUTTING THESE IN THE REPORT ENGINE. Purchasing already had a
 * CSV of its own, written by hand. Every format beyond it - Excel, PDF, the
 * printable page, the letterhead, the three signature blocks - exists once, in
 * the shared writers, and a definition inherits all of it. So the assertion
 * that matters here is not "the report works", it is "the registry knows about
 * it", because that is what buys the rest.
 */
beforeEach(function () {
    // ⚠ The real posting path refuses a voucher dated in the future, so these
    // tests have to say when "now" is rather than depending on the wall clock.
    Carbon::setTestNow('2026-09-30 12:00:00');

    $this->business = Business::create(['name' => 'Standing Co', 'slug' => 'standing-co-rep']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['purchasing.reports.view', 'purchasing.expense.record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['purchasing.reports.view', 'purchasing.expense.record']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Owner');

    // ⚠ firstOrCreate: the chart of accounts OWNS 6225 now (it was planted by
    // hand in production for the hosting bill and joined the seeder with the
    // expense-claim categories). A test that insists on creating it collides.
    $this->account = Account::firstOrCreate(
        ['code' => '6225'],
        [
            'business_id' => $this->business->id,
            'name' => 'Software and Hosting', 'type' => AccountType::Expense,
            'is_postable' => true, 'is_active' => true,
        ],
    );

    // ⚠⚠ THE MONEY ACCOUNT NEEDS ITS LEDGER ACCOUNT NOW. The expense register
    // reads JOURNAL LINES since 10 Sep 2026, so these tests have to record a
    // voucher the way the application does - through RecordExpenseVoucher, which
    // posts - rather than inserting rows that look like one.
    $cash = Account::firstOrCreate(
        ['code' => '1120'],
        [
            'business_id' => $this->business->id,
            'name' => 'Cash at Bank', 'type' => AccountType::Asset,
            'is_postable' => true, 'is_active' => true,
        ],
    );

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank',
        'account_id' => $cash->id, 'is_active' => true,
    ]);

    // ⚠ PUR-002 (14 Oct 2026): this file used to map an invented
    // `purchasing.expense` key to 6225 as "the anchor the posting checks".
    // Nothing reads that key, and a `purchasing.` mapping makes the head
    // Purchasing's own, which the expense voucher now refuses like the
    // Accounting voucher does. The voucher posts without it.

    $this->record = fn (string $on, string $amount, string $what, string $payee = 'Nikola Host') => app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->bank,
        spentOn: $on,
        lines: [['account' => $this->account, 'amount' => $amount, 'description' => $what]],
        createdBy: $this->user,
        paidTo: $payee,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('offers both purchasing reports to somebody who may see them', function () {
    // ⚠⚠ A report the registry has never heard of is a class. Nine engines in
    // this system have shipped complete and unreachable, and a definition
    // registered in the wrong module's provider would be the tenth.
    $keys = app(ReportRegistry::class)->availableTo($this->user)->keys();

    expect($keys)->toContain('purchasing_expense_register')
        ->and($keys)->toContain('purchasing_standing_costs');
});

it('lists every line of an expense, not just the voucher total', function () {
    // A payment split across two accounts is the case a document-level register
    // cannot show, and the only case where the split matters.
    //
    // ⚠⚠ RECORDED THE WAY THE APPLICATION RECORDS IT, not assembled by hand.
    // The register reads the ledger now, so a fixture that inserts voucher rows
    // without posting a journal would prove nothing about the real path - which
    // is precisely how this test passed against a register that could not see
    // half the money.
    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $this->bank,
        spentOn: '2026-09-20',
        lines: [
            ['account' => $this->account, 'amount' => '4900', 'description' => 'Shared hosting'],
            ['account' => $this->account, 'amount' => '1000', 'description' => 'Domain renewal'],
        ],
        createdBy: $this->user,
        paidTo: 'Nikola Host',
        reference: 'NIKOLA-2026-09',
    );

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30']))
        ->all();

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'amount'))->toBe([4900.0, 1000.0])
        ->and($rows[0]['source'])->toBe('Payment voucher')
        ->and($rows[0]['payee'])->toBe('Nikola Host')
        ->and($rows[0]['paid_from'])->toBe('BRAC Bank')
        ->and($rows[0]['description'])->toBe('Shared hosting');

    expect(ExpenseVoucher::count())->toBe(1);
});

it('keeps an expense outside the asked-for dates off the register', function () {
    // ⚠ Both are REAL postings. Written as bare rows this test passed against a
    // register that could not see either of them, which proved nothing at all
    // about the date filter it claims to be about.
    ($this->record)('2026-08-15', '100', 'August thing');
    ($this->record)('2026-09-15', '250', 'September thing');

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30']))
        ->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['description'])->toBe('September thing');
});

/**
 * ⛔⛔ THE DEFECT THE WHOLE REWRITE EXISTS FOR (Joy, 10 Sep 2026): "when customer
 * do some Expenses, they did for office, if it is, then why they not shows in
 * the Purchasing > Expenses". A staff claim booked at approval clears a payable
 * when it is paid and writes NO voucher, so it was invisible on the expenses
 * screen and in this register while being a perfectly real operating cost.
 */
it('shows a staff expense claim beside a payment voucher, because both are the company spending money', function () {
    ($this->record)('2026-09-10', '500', 'Paid from the till');

    // The claim's own posting: Dr the expense account / Cr what we owe the
    // employee. No voucher exists anywhere in this, and that was the problem.
    $employee = Employee::create([
        'business_id' => $this->business->id,
        'employee_code' => 'BDG-000031', 'first_name' => 'Musa', 'last_name' => 'Islam',
        'status' => 'active', 'employment_type' => 'permanent',
    ]);

    $claim = ExpenseClaim::create([
        'business_id' => $this->business->id, 'employee_id' => $employee->id,
        'claim_number' => 'EC-00011', 'submitted_at' => now(),
        'spent_on' => '2026-09-12', 'amount' => '180', 'purpose' => 'Courier to Savar',
    ]);

    $payable = Account::firstOrCreate(
        ['code' => '2145'],
        ['business_id' => $this->business->id, 'name' => 'Employee Reimbursements Payable',
            'type' => AccountType::Liability, 'is_postable' => true, 'is_active' => true,
            'system_slug' => 'employee_reimbursements_payable'],
    );

    app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse('2026-09-12'),
            lines: [
                JournalLineDraft::debit(
                    $this->account->id,
                    Money::ofTaka('180'),
                    'Courier to Savar',
                    $employee,
                ),
                JournalLineDraft::credit(
                    $payable->id,
                    Money::ofTaka('180'),
                    'Owed to Musa Islam',
                    $employee,
                ),
            ],
            memo: 'Expense claim EC-00011 approved',
            source: $claim,
        ),
        $this->user,
    );

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30']))
        ->all();

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'source'))->toBe(['Payment voucher', 'Staff expense claim'])
        ->and($rows[1]['document'])->toBe('EC-00011')
        ->and($rows[1]['payee'])->toBe('Musa Islam')
        ->and($rows[1]['amount'])->toBe(180.0)
        // ⚠ And only the DEBIT. The credit to the payable is not a cost, and
        // including it would net the register against itself.
        ->and(array_sum(array_column($rows, 'amount')))->toBe(680.0);
});

it('levels a yearly commitment to a month before totalling it', function () {
    // ⚠⚠ THE DEFECT THIS TEST EXISTS TO PREVENT. Adding 4,900 a month to 1,200
    // a year gives 6,100, which is neither figure and reads as a monthly cost.
    // Only the levelled columns are totalled, and that is why.
    RecurringExpense::create([
        'business_id' => $this->business->id, 'name' => 'Nikola Host shared hosting',
        'expense_account_id' => $this->account->id, 'money_account_id' => $this->bank->id,
        'amount' => 4900, 'frequency' => RecurringFrequency::Monthly, 'day_of_month' => 31,
        'start_date' => '2026-09-01', 'status' => RecurringExpenseStatus::Active,
    ]);
    RecurringExpense::create([
        'business_id' => $this->business->id, 'name' => 'Domain',
        'expense_account_id' => $this->account->id,
        'amount' => 1200, 'frequency' => RecurringFrequency::Yearly, 'day_of_month' => 1,
        'start_date' => '2026-09-01', 'status' => RecurringExpenseStatus::Active,
    ]);

    $report = app(StandingCostsReport::class);
    $rows = collect($report->rows(new ReportParameters(['state' => 'active']))->all());

    expect($report->totalColumns())->toBe(['per_month', 'per_year'])
        ->and($report->totalColumns())->not->toContain('amount')
        ->and(round($rows->sum('per_month'), 2))->toBe(5000.0)   // 4,900 + 100
        ->and(round($rows->sum('per_year'), 2))->toBe(60000.0);  // 58,800 + 1,200
});

it('says the last day of the month rather than day 31', function () {
    RecurringExpense::create([
        'business_id' => $this->business->id, 'name' => 'Nikola Host shared hosting',
        'expense_account_id' => $this->account->id, 'money_account_id' => $this->bank->id,
        'amount' => 4900, 'frequency' => RecurringFrequency::Monthly, 'day_of_month' => 31,
        'start_date' => '2026-09-01', 'status' => RecurringExpenseStatus::Active,
    ]);

    $rows = app(StandingCostsReport::class)->rows(new ReportParameters(['state' => 'active']))->all();

    expect($rows[0]['due_day'])->toBe('Last day of the month');
});

it('reports the next OPEN period as what is next due', function () {
    // ⚠ A month already recorded must not answer "next due" - the answer would
    // be a date in the past on a page somebody signs.
    $schedule = RecurringExpense::create([
        'business_id' => $this->business->id, 'name' => 'Nikola Host shared hosting',
        'expense_account_id' => $this->account->id, 'money_account_id' => $this->bank->id,
        'amount' => 4900, 'frequency' => RecurringFrequency::Monthly, 'day_of_month' => 31,
        'start_date' => '2026-09-01', 'status' => RecurringExpenseStatus::Active,
    ]);

    app(RollRecurringExpenses::class)->forSchedule($schedule, CarbonImmutable::parse('2026-09-15'));

    $september = $schedule->occurrences()->orderBy('due_on')->first();

    $rows = app(StandingCostsReport::class)->rows(new ReportParameters(['state' => 'active']))->all();
    expect(CarbonImmutable::parse($rows[0]['next_due'])->toDateString())->toBe('2026-09-30');

    app(SettleRecurringOccurrence::class)
        ->skipped($september, $this->user, 'Paid outside the ERP');

    $rows = app(StandingCostsReport::class)->rows(new ReportParameters(['state' => 'active']))->all();
    expect(CarbonImmutable::parse($rows[0]['next_due'])->toDateString())->toBe('2026-10-31');
});

it('states on its own face that a standing cost has not been paid', function () {
    // ⛔⛔ Three people sign the bottom of this page. It totals FORECASTS, and
    // the only defence against it being read as an expense register is that it
    // says so where they will read it.
    $meta = app(StandingCostsReport::class)->meta(new ReportParameters([]));

    expect($meta->lockNote)->toContain('EXPECTS')
        ->and($meta->lockNote)->toContain('expense register');
});

it('filters the register by the account the money was paid from', function () {
    $petty = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty Cash', 'type' => 'cash',
        'account_id' => $this->bank->account_id, 'is_active' => true,
    ]);
    ($this->record)('2026-09-10', '300', 'Paid by bank');
    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $petty,
        spentOn: '2026-09-11',
        lines: [['account' => $this->account, 'amount' => '120', 'description' => 'Paid from petty cash']],
        createdBy: $this->user,
        paidTo: 'Tea stall',
    );

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30', 'money_account_id' => $petty->id]))
        ->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['description'])->toBe('Paid from petty cash')
        ->and($rows[0]['paid_from'])->toBe('Petty Cash');
});
