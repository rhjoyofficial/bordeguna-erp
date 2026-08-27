<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 2.4 of Accounting: the Trial Balance and accounting:verify-ledger.
 *
 * ⚠⚠ THE MILESTONE THIS PHASE IS FINISHED BY: a trial balance that balances
 * from MANUAL JOURNALS ALONE, with no subsystem connected and no special case
 * anywhere. If it needed one, the foundation would be wrong.
 *
 * See ACCOUNTING-LEDGER-ARCHITECTURE.md §6 and §7.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create([
        'name' => 'Balance Co',
        'slug' => 'balance-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->bank = Account::bySlug('cash_at_bank');
    $this->cash = Account::bySlug('cash_in_hand');
    $this->capital = Account::bySlug('owners_capital');
    $this->opening = Account::bySlug('opening_balance_equity');
    $this->rent = Account::where('code', '6200')->first();

    $this->post = function (int $debit, int $credit, string $amount, string $date = '2026-08-03', JournalType $type = JournalType::Manual) {
        return app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: [
                JournalLineDraft::debit($debit, Money::ofTaka($amount)),
                JournalLineDraft::credit($credit, Money::ofTaka($amount)),
            ],
            type: $type,
        ), $this->founder);
    };

    $this->balance = fn (string $from = '2026-07-01', string $to = '2026-08-03') => TrialBalance::between(
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    );
});

// ─── THE MILESTONE ──────────────────────────────────────────────────────────

it('balances from manual journals alone, with no subsystem connected', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');
    ($this->post)($this->rent->id, $this->bank->id, '12000');
    ($this->post)($this->cash->id, $this->bank->id, '25000');

    $trialBalance = ($this->balance)();

    // ⚠⚠ EXACTLY zero, not "within a taka". Only possible to ask because the
    // amounts are whole paisa.
    expect($trialBalance->balances())->toBeTrue()
        ->and($trialBalance->difference()->minor)->toBe(0)
        ->and($trialBalance->totalDebits()->format())->toBe($trialBalance->totalCredits()->format());

    $bank = $trialBalance->rows->first(fn ($row) => $row->account->id === $this->bank->id);

    // 500,000 in, 12,000 and 25,000 out.
    expect($bank->closing()->format())->toBe('463,000.00')
        ->and($bank->closing()->isDebit())->toBeTrue();

    $capital = $trialBalance->rows->first(fn ($row) => $row->account->id === $this->capital->id);
    expect($capital->closing()->absolute()->format())->toBe('500,000.00')
        ->and($capital->closing()->isCredit())->toBeTrue();
});

it('separates what came before the period from what happened in it', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000', '2026-06-15');
    ($this->post)($this->rent->id, $this->bank->id, '12000', '2026-07-20');

    $trialBalance = ($this->balance)('2026-07-01', '2026-07-31');
    $bank = $trialBalance->rows->first(fn ($row) => $row->account->id === $this->bank->id);

    // ⚠ The opening balance is everything before the window, not a stored
    // number. A stored opening balance is a second source of truth that goes
    // stale the moment somebody backdates an entry into a prior period.
    expect($bank->opening->format())->toBe('500,000.00')
        ->and($bank->credit->absolute()->format())->toBe('12,000.00')
        ->and($bank->closing()->format())->toBe('488,000.00');

    expect($trialBalance->balances())->toBeTrue();
});

it('shows both sides of an account that moved and came back to zero', function () {
    ($this->post)($this->cash->id, $this->capital->id, '9000');
    ($this->post)($this->capital->id, $this->cash->id, '9000');

    $cash = ($this->balance)()->rows->first(fn ($row) => $row->account->id === $this->cash->id);

    // ⚠ Debits and credits are summed separately rather than netted. An account
    // with 9,000 in and 9,000 out is not a quiet account, and a single blank
    // line for it would hide a day's activity.
    expect($cash->debit->format())->toBe('9,000.00')
        ->and($cash->credit->absolute()->format())->toBe('9,000.00')
        ->and($cash->closing()->isZero())->toBeTrue()
        ->and($cash->hasActivity())->toBeTrue();
});

it('still balances after a journal is reversed', function () {
    $journal = ($this->post)($this->rent->id, $this->bank->id, '12000');
    app(ReverseJournal::class)->handle($journal, $this->founder);

    $trialBalance = ($this->balance)();

    expect($trialBalance->balances())->toBeTrue();

    $rent = $trialBalance->rows->first(fn ($row) => $row->account->id === $this->rent->id);

    // Both entries are still on the record and they cancel. That is what
    // "corrected, not erased" looks like on a report.
    expect($rent->closing()->isZero())->toBeTrue()
        ->and($rent->debit->format())->toBe('12,000.00')
        ->and($rent->credit->absolute()->format())->toBe('12,000.00');
});

it('hides accounts nothing has happened to', function () {
    ($this->post)($this->bank->id, $this->capital->id, '1000');

    $trialBalance = ($this->balance)();

    // 59 accounts in the chart; only the two that were used are worth showing.
    expect($trialBalance->rows->filter(fn ($row) => $row->isWorthShowing())->count())->toBe(2)
        ->and(Account::count())->toBeGreaterThan(50);
});

it('keeps one business trial balance out of another', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    $other = Business::create(['name' => 'Not Ours', 'slug' => 'not-ours', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);

    expect(($this->balance)()->rows)->toBeEmpty();
});

// ─── The integrity command ──────────────────────────────────────────────────

it('reports a clean ledger', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    $result = LedgerIntegrity::verifyAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty()
        ->and($result['journals'])->toBe(1);
});

it('actually looks at something when it runs with no tenant', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');
    ($this->post)($this->rent->id, $this->bank->id, '12000');

    // ⚠⚠ THE TRAP THIS PROJECT HAS MET FOUR TIMES. A console command carries no
    // BusinessContext, every model here is business-scoped, and BusinessScope
    // fails closed on `1 = 0` - so a naive verifier reports "all consistent"
    // while examining NOTHING, which is the worst possible failure for an
    // integrity check. Leaving the tenant first is the whole point of the test.
    TenantScope::leave();
    expect(Journal::count())->toBe(0);

    $result = LedgerIntegrity::verifyAllBusinesses();

    // It saw the journals despite starting outside every tenant.
    expect($result['journals'])->toBe(2)
        ->and($result['businesses'])->toBe(1)
        ->and($result['problems'])->toBeEmpty();
});

it('puts the caller back in the business it found them in', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    // ⚠⚠ THE DEFECT THIS TEST WAS WRITTEN FOR, and it was found by accident:
    // verifying every business walks every tenant, and finishing with
    // TenantScope::leave() silently evicted whoever called it. The next query
    // then returned nothing, which reads as missing data rather than a lost
    // tenant. Same family as the three bugs where state outlived its unit of
    // work, seen from the other side.
    expect(app(BusinessContext::class)->id())->toBe($this->business->id);

    LedgerIntegrity::verifyAllBusinesses();

    expect(app(BusinessContext::class)->id())->toBe($this->business->id)
        ->and(($this->balance)()->rows)->not->toBeEmpty();
});

it('leaves no tenant behind when it was called without one', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    TenantScope::leave();
    LedgerIntegrity::verifyAllBusinesses();

    // A console command started outside every tenant must end outside every
    // tenant, or the next command in the same process inherits one.
    expect(app(BusinessContext::class)->id())->toBeNull();
});

it('catches a journal that has been forced out of balance', function () {
    $journal = ($this->post)($this->bank->id, $this->capital->id, '500000');

    // ⚠ Behind the ledger's back, because there is no legitimate way to do this
    // - which is precisely why the check has to exist. `update()` throws and the
    // model events refuse it, so corruption can only arrive from outside the
    // application, and that is what the command is for.
    DB::table('journal_lines')->where('journal_id', $journal->id)->limit(1)->update(['amount_minor' => 1]);

    $result = LedgerIntegrity::verifyAllBusinesses();

    expect($result['problems'])->toHaveCount(2);
    expect(implode(' ', $result['problems']))
        ->toContain('does not balance')
        ->toContain($journal->journal_number);
});

it('catches a line filed under the wrong business', function () {
    $journal = ($this->post)($this->bank->id, $this->capital->id, '500000');
    $other = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-tb', 'fiscal_year_start_month' => 7]);

    // ⚠ business_id on a line is a denormalised copy of its journal's, kept so
    // the trial balance need not join to find out whose it is. That is exactly
    // the shape that let a transfer leg be written against the wrong warehouse
    // and pass level 1 of inventory:verify-ledger.
    DB::table('journal_lines')->where('journal_id', $journal->id)->limit(1)->update(['business_id' => $other->id]);

    $result = LedgerIntegrity::verifyAllBusinesses();

    expect(implode(' ', $result['problems']))->toContain('filed under a different business');
});

it('warns rather than fails while opening balances are half entered', function () {
    // Only one side of the opening position has been typed so far.
    ($this->post)($this->bank->id, $this->opening->id, '250000', '2026-06-30', JournalType::OpeningBalance);

    $result = LedgerIntegrity::verifyAllBusinesses();

    // ⚠⚠ A WARNING, never a problem. Half-entered opening balances are a normal
    // state that can last days, and a command that is red throughout setup is a
    // command nobody looks at by the time it matters.
    expect($result['problems'])->toBeEmpty()
        ->and(implode(' ', $result['warnings']))->toContain('Opening Balance Equity is 250,000.00, not zero');
});

it('goes quiet once every opening figure is in', function () {
    // Assets on one side, capital on the other, and the suspense account nets
    // to zero. That is the self-check: a trial balance can balance perfectly and
    // still be wrong, and this is the only thing that catches it.
    ($this->post)($this->bank->id, $this->opening->id, '250000', '2026-06-30', JournalType::OpeningBalance);
    ($this->post)($this->opening->id, $this->capital->id, '250000', '2026-06-30', JournalType::OpeningBalance);

    $result = LedgerIntegrity::verifyAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty();

    $opening = TrialBalance::between(Carbon::parse('2026-06-01'), Carbon::parse('2026-08-03')->endOfDay())
        ->rowFor('opening_balance_equity');

    expect($opening->closing()->isZero())->toBeTrue();
});

it('warns when something posts to the opening account after the books are open', function () {
    ($this->post)($this->bank->id, $this->opening->id, '250000', '2026-06-30', JournalType::OpeningBalance);
    ($this->post)($this->opening->id, $this->capital->id, '250000', '2026-06-30', JournalType::OpeningBalance);

    // An ordinary manual journal touching the suspense account months later.
    ($this->post)($this->opening->id, $this->cash->id, '5000', '2026-08-03');

    $result = LedgerIntegrity::verifyAllBusinesses();

    expect(implode(' ', $result['warnings']))->toContain('outside an opening balance entry');
});

it('warns when an account carrying postings is later retired', function () {
    ($this->post)($this->rent->id, $this->bank->id, '12000');

    $this->rent->update(['is_active' => false]);

    $result = LedgerIntegrity::verifyAllBusinesses();

    // ⚠ A warning, not a problem: retiring an account once its history is
    // complete is a legitimate move, and failing the command for it would train
    // people to ignore the command.
    expect($result['problems'])->toBeEmpty()
        ->and(implode(' ', $result['warnings']))->toContain('6200');
});

it('checks the numbers the user is actually shown', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    // ⚠⚠ Level 4 runs the SAME TrialBalance the screen renders rather than a
    // second sum that happens to agree. This project has shipped a defect in a
    // presentation path twice.
    $shown = ($this->balance)('2026-06-01', '2026-08-03');
    $result = LedgerIntegrity::verifyAllBusinesses();

    expect($shown->balances())->toBeTrue()
        ->and($result['problems'])->toBeEmpty();
});

it('runs the artisan command and passes', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    $this->artisan('accounting:verify-ledger')
        ->expectsOutputToContain('The ledger is consistent')
        ->assertSuccessful();
});

it('runs the artisan command and fails on a corrupt ledger', function () {
    $journal = ($this->post)($this->bank->id, $this->capital->id, '500000');
    DB::table('journal_lines')->where('journal_id', $journal->id)->limit(1)->update(['amount_minor' => 7]);

    $this->artisan('accounting:verify-ledger')->assertFailed();
});

// ─── The screen ─────────────────────────────────────────────────────────────

it('renders the trial balance for somebody who may read the books', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    $response = $this->actingAs($this->founder)->get(route('accounting.trial-balance'));

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['totals']['balances'])->toBeTrue()
        // ⚠ Formatted, always. `19200.00000000` has reached a real user twice.
        ->and($props['totals']['debit'])->toBe('500,000.00')
        ->and($props['totals']['credit'])->toBe('500,000.00')
        ->and($props['rows'])->toHaveCount(2);
});

it('refuses the trial balance to somebody with no accounting permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)
        ->get(route('accounting.trial-balance'))
        ->assertForbidden();
});

it('defaults to the fiscal year rather than the calendar month', function () {
    $response = $this->actingAs($this->founder)->get(route('accounting.trial-balance'));

    // Bor de Guna's year runs 1 July to 30 June (C-GL-4). A July default would
    // show four weeks of a twelve-month story.
    expect($response->viewData('page')['props']['from'])->toBe('2026-07-01');
});

it('swaps a backwards date range rather than showing nothing', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');

    $response = $this->actingAs($this->founder)
        ->get(route('accounting.trial-balance', ['from' => '2026-08-03', 'to' => '2026-07-01']));

    // A window that runs backwards returns nothing, and looks like a bug in the
    // books rather than a typo in a date field.
    expect($response->viewData('page')['props']['from'])->toBe('2026-07-01')
        ->and($response->viewData('page')['props']['rows'])->not->toBeEmpty();
});

it('says so plainly when nothing has been posted', function () {
    $response = $this->actingAs($this->founder)->get(route('accounting.trial-balance'));

    expect($response->viewData('page')['props']['empty'])->toBeTrue()
        ->and($response->viewData('page')['props']['rows'])->toBeEmpty();
});

it('does not lose a line when a journal has more than two of them', function () {
    // A real journal is rarely two lines: rent, a deduction and the bank.
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-03'),
        lines: [
            JournalLineDraft::debit($this->rent->id, Money::ofTaka('12000')),
            JournalLineDraft::debit($this->cash->id, Money::ofTaka('3000')),
            JournalLineDraft::credit($this->bank->id, Money::ofTaka('15000')),
        ],
    ), $this->founder);

    $trialBalance = ($this->balance)();

    expect($trialBalance->balances())->toBeTrue()
        ->and($trialBalance->rows->filter(fn ($row) => $row->isWorthShowing())->count())->toBe(3)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});
