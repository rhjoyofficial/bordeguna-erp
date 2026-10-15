<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Actions\SettleRecurringOccurrence;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Standing costs: the subscriptions and monthly bills that arrive whether or not
 * anybody remembers them (Joy, 1 September 2026).
 *
 * ⛔⛔ The rule every test here defends: THIS MODULE NEVER POSTS. An expense
 * voucher in this ERP is paid the moment it is recorded, so a schedule that
 * created one would record a payment nobody had made - and would keep doing it
 * every month.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Standing Co', 'slug' => 'standing-co']);
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

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
    ]);

    $this->schedule = fn (array $overrides = []) => RecurringExpense::create([
        'business_id' => $this->business->id,
        'name' => 'Nikola Host shared hosting',
        'expense_account_id' => $this->account->id,
        'money_account_id' => $this->bank->id,
        'amount' => 4900,
        'frequency' => RecurringFrequency::Monthly,
        'day_of_month' => 31,
        'start_date' => '2026-07-01',
        'reference_prefix' => 'NIKOLA',
        'status' => RecurringExpenseStatus::Active,
        ...$overrides,
    ]);
});

it('treats day 31 as the last day of every month, however long it is', function () {
    // ⚠⚠ THE REASON THIS TEST EXISTS. Joy: "the permonth bill is 4900 taka last
    // day of pay in last day of the month." Storing 29 would be wrong in eleven
    // months out of twelve, and Carbon's addMonths on a 31st rolls into the
    // NEXT month, which would have quietly moved February's bill to 3 March.
    app(RollRecurringExpenses::class)->forSchedule(($this->schedule)(), CarbonImmutable::parse('2027-01-15'));

    $dates = RecurringExpenseOccurrence::orderBy('due_on')->pluck('due_on')
        ->map(fn ($d) => $d->toDateString())->all();

    expect($dates)->toContain('2026-07-31')
        ->and($dates)->toContain('2026-09-30')   // 30 days
        ->and($dates)->toContain('2026-11-30')
        ->and($dates)->toContain('2027-02-28');  // and February, which is the point
});

it('generates each period exactly once, however often it runs', function () {
    // ⚠⚠ Idempotent BY CONSTRUCTION - a unique index on the source key - so a
    // nightly job, a page load and a restored backup cannot between them create
    // two reminders for one month.
    $schedule = ($this->schedule)();
    $roll = app(RollRecurringExpenses::class);
    $on = CarbonImmutable::parse('2026-09-15');

    $first = $roll->forSchedule($schedule, $on);
    $second = $roll->forSchedule($schedule, $on);
    $third = $roll->forSchedule($schedule, $on);

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and($third)->toBe(0)
        ->and(RecurringExpenseOccurrence::count())->toBe($first);
});

it('never writes a voucher or a journal of its own', function () {
    // ⛔⛔ The whole design in one assertion. Rolling forward creates reminders
    // and nothing else; the money is recorded by a human on the expense screen.
    app(RollRecurringExpenses::class)->forSchedule(($this->schedule)(), CarbonImmutable::parse('2026-09-15'));

    expect(RecurringExpenseOccurrence::count())->toBeGreaterThan(0)
        ->and(Journal::count())->toBe(0)
        ->and(DB::table('expense_vouchers')->count())->toBe(0);
});

it('copies the expected amount instead of following a price rise backwards', function () {
    // ⚠ A price change next year must not restate what an earlier month expected.
    $schedule = ($this->schedule)();
    app(RollRecurringExpenses::class)->forSchedule($schedule, CarbonImmutable::parse('2026-09-15'));

    $schedule->update(['amount' => 6500]);

    expect(RecurringExpenseOccurrence::orderBy('due_on')->first()->expected_amount)->toEqual('4900.00');
});

it('stops at the end date and generates nothing for a paused schedule', function () {
    app(RollRecurringExpenses::class)->forSchedule(
        ($this->schedule)(['end_date' => '2026-08-31']),
        CarbonImmutable::parse('2026-12-01'),
    );

    expect(RecurringExpenseOccurrence::count())->toBe(2); // July and August only

    RecurringExpenseOccurrence::query()->delete();

    $paused = ($this->schedule)(['name' => 'Paused thing', 'status' => RecurringExpenseStatus::Paused]);
    app(RollRecurringExpenses::class)->handle(CarbonImmutable::parse('2026-12-01'));

    expect(RecurringExpenseOccurrence::where('recurring_expense_id', $paused->id)->count())->toBe(0);
});

it('refuses to settle the same period twice', function () {
    // ⛔ A second settle would either double-count a month or quietly replace
    // the voucher that already answered for it.
    app(RollRecurringExpenses::class)->forSchedule(($this->schedule)(), CarbonImmutable::parse('2026-09-15'));

    $occurrence = RecurringExpenseOccurrence::orderBy('due_on')->first();
    app(SettleRecurringOccurrence::class)->skipped($occurrence, $this->user, 'Cancelled before renewal');

    expect(fn () => app(SettleRecurringOccurrence::class)->skipped($occurrence->fresh(), $this->user, 'again'))
        ->toThrow(ValidationException::class);
});

it('names the reference from the period, in our own convention', function () {
    $schedule = ($this->schedule)();

    expect($schedule->referenceFor(CarbonImmutable::parse('2026-09-30')))->toBe('NIKOLA-2026-09');
});

it('levels every frequency to a month so unlike costs can be added up', function () {
    $yearly = ($this->schedule)(['name' => 'Domain', 'frequency' => RecurringFrequency::Yearly, 'amount' => 1200]);

    expect($yearly->annualCommitment())->toBe(1200.0)
        ->and($yearly->monthlyRunRate())->toBe(100.0);
});

it('shows the schedule and what it owes on the screen', function () {
    ($this->schedule)();

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->get('/purchasing/recurring-expenses')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/recurring-expenses/index')
            ->has('schedules', 1)
            // ⚠ Rolled on read, so a cost added this morning is due this morning.
            ->has('due')
            ->where('totals.monthly', fn ($v) => (float) $v === 4900.0)
            ->where('totals.annual', fn ($v) => (float) $v === 58800.0));
});

it('is reachable from the sidebar, not just by URL', function () {
    // ⚠⚠ A reminder nobody can reach is a note. Nine engines in this system have
    // shipped complete and unreachable.
    // Since the Purchases redesign (15 Oct 2026) the sidebar draws the module
    // from its sections registry, so the address lives there, as a tab of
    // Expenses, gated on the same read flag the link had.
    expect(file_get_contents(resource_path('js/components/purchasing/purchases-sections.ts')))
        ->toMatch("/url: '\/purchasing\/recurring-expenses'[^}]*canViewPurchasing/")
        ->and(file_get_contents(resource_path('js/components/app-sidebar.tsx')))
        ->toContain('sectionNavItems(PURCHASES_SECTIONS');
});

// ⚠⚠ A bare exists accepted another business's supplier and bank account, and
// any account at all as the expense head; the schedule stored them and the
// wrong head only failed on the day the cost fell due.
it('refuses another business ids and a head that is not a postable expense', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $theirBank = MoneyAccount::create([
        'business_id' => $other->id, 'name' => 'Their bank', 'type' => 'bank', 'is_active' => true,
    ]);
    $revenue = Account::firstOrCreate(
        ['code' => '4999'],
        ['name' => 'Test revenue', 'type' => AccountType::Income, 'is_postable' => true, 'is_active' => true],
    );

    $valid = [
        'name' => 'Hosting', 'expense_account_id' => $this->account->id, 'money_account_id' => $this->bank->id,
        'amount' => '4900', 'frequency' => RecurringFrequency::Monthly->value, 'day_of_month' => 1,
        'start_date' => '2026-07-01',
    ];
    $as = fn () => $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id]);

    $as()->post('/purchasing/recurring-expenses', array_merge($valid, ['money_account_id' => $theirBank->id]))
        ->assertSessionHasErrors('money_account_id');
    $as()->post('/purchasing/recurring-expenses', array_merge($valid, ['expense_account_id' => $revenue->id]))
        ->assertSessionHasErrors('expense_account_id');

    expect(RecurringExpense::count())->toBe(0);

    $as()->post('/purchasing/recurring-expenses', $valid)->assertSessionHasNoErrors();

    expect(RecurringExpense::count())->toBe(1);
});
