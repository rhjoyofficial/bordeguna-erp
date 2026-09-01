<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Actions\SettleRecurringOccurrence;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Reporting\Definitions\ExpenseRegisterReport;
use App\Modules\Purchasing\Reporting\Definitions\StandingCostsReport;
use Carbon\CarbonImmutable;
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

    $this->account = Account::create([
        'business_id' => $this->business->id,
        'code' => '6225', 'name' => 'Software and Hosting', 'type' => AccountType::Expense,
        'is_postable' => true, 'is_active' => true,
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
    ]);
});

it('offers both purchasing reports to somebody who may see them', function () {
    // ⚠⚠ A report the registry has never heard of is a class. Nine engines in
    // this system have shipped complete and unreachable, and a definition
    // registered in the wrong module's provider would be the tenth.
    $keys = app(ReportRegistry::class)->availableTo($this->user)->keys();

    expect($keys)->toContain('purchasing_expense_register')
        ->and($keys)->toContain('purchasing_standing_costs');
});

it('lists every line of an expense, not just the voucher total', function () {
    // A payment split across two accounts is the case a voucher-level register
    // cannot show, and the only case where the split matters.
    $voucher = ExpenseVoucher::create([
        'business_id' => $this->business->id,
        'voucher_number' => 'EXP-0001',
        'spent_on' => '2026-09-30',
        'money_account_id' => $this->bank->id,
        'paid_to' => 'Nikola Host',
        'total' => 5900, 'created_by' => $this->user->id,
        'reference' => 'NIKOLA-2026-09',
    ]);

    $voucher->lines()->create([
        'business_id' => $this->business->id,
        'account_id' => $this->account->id, 'description' => 'Shared hosting', 'amount' => 4900,
    ]);
    $voucher->lines()->create([
        'business_id' => $this->business->id,
        'account_id' => $this->account->id, 'description' => 'Domain renewal', 'amount' => 1000,
    ]);

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30']))
        ->all();

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'amount'))->toBe([4900.0, 1000.0])
        ->and($rows[0]['payee'])->toBe('Nikola Host')
        ->and($rows[0]['paid_from'])->toBe('BRAC Bank');
});

it('keeps an expense outside the asked-for dates off the register', function () {
    $voucher = ExpenseVoucher::create([
        'business_id' => $this->business->id,
        'voucher_number' => 'EXP-0002', 'spent_on' => '2026-08-15',
        'money_account_id' => $this->bank->id, 'paid_to' => 'Someone', 'total' => 100, 'created_by' => $this->user->id,
    ]);
    $voucher->lines()->create([
        'business_id' => $this->business->id,
        'account_id' => $this->account->id, 'description' => 'August thing', 'amount' => 100,
    ]);

    $rows = app(ExpenseRegisterReport::class)
        ->rows(new ReportParameters(['spent_from' => '2026-09-01', 'spent_to' => '2026-09-30']))
        ->all();

    expect($rows)->toBeEmpty();
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
