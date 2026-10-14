<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountLedger;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerEntry;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One account's postings, in order, with a running balance.
 *
 * ⚠⚠ The two properties worth testing are not the arithmetic - the arithmetic is
 * one addition. They are ORDER (C-GLA-2) and the PAGE SEED (C-GLA-3). A running
 * balance is only meaningful if the order is total, and only correct on page two
 * if the seed carries page one. Both fail silently and look plausible.
 *
 * See GENERAL-LEDGER-AND-AGEING-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-13 10:00:00');

    $this->business = Business::create([
        'name' => 'Ledger Co',
        'slug' => 'ledger-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
    $this->outsider->assignRole($outsider);

    $this->bank = moneyHead('cash_at_bank');
    $this->capital = Account::bySlug('owners_capital');
    $this->payable = Account::bySlug('accounts_payable');

    $this->post = function (string $amount, string $date, ?Supplier $party = null) {
        return app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: [
                JournalLineDraft::debit($this->bank->id, Money::ofTaka($amount), null, $party),
                JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount)),
            ],
            memo: "In {$amount}",
        ), $this->reader);
    };

    $this->ledger = fn (array $options = []) => AccountLedger::for(
        account: $options['account'] ?? $this->bank,
        from: Carbon::parse($options['from'] ?? '2026-07-01')->startOfDay(),
        to: Carbon::parse($options['to'] ?? '2026-08-31')->endOfDay(),
        partyType: $options['partyType'] ?? null,
        partyId: $options['partyId'] ?? null,
        perPage: $options['perPage'] ?? 100,
        page: $options['page'] ?? 1,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE RUNNING BALANCE ────────────────────────────────────────────────────

it('accumulates the running balance in posting order', function () {
    ($this->post)('1000', '2026-07-05');
    ($this->post)('500', '2026-07-06');
    ($this->post)('250', '2026-07-07');

    $balances = ($this->ledger)()->entries->map(fn (LedgerEntry $entry) => $entry->balance->minor)->all();

    expect($balances)->toBe([100000, 150000, 175000]);
});

it('opens with everything before the window and lists none of it', function () {
    ($this->post)('9000', '2026-06-20');   // before the window
    ($this->post)('1000', '2026-07-05');

    $ledger = ($this->ledger)();

    expect($ledger->opening->minor)->toBe(900000)
        ->and($ledger->entries)->toHaveCount(1)
        ->and($ledger->entries->first()->balance->minor)->toBe(1000000)
        ->and($ledger->closing()->minor)->toBe(1000000);
});

// ─── C-GLA-2. ORDER ─────────────────────────────────────────────────────────

it('orders postings on the same date by journal and line, not by chance', function () {
    // ⚠ Three journals on ONE day. Date alone cannot order these, so a report
    // that ordered by date only would return them in whatever sequence the
    // engine felt like and the running balance would differ between two people
    // opening the same page.
    $first = ($this->post)('100', '2026-07-05');
    $second = ($this->post)('200', '2026-07-05');
    $third = ($this->post)('300', '2026-07-05');

    $numbers = fn () => ($this->ledger)()->entries
        ->map(fn (LedgerEntry $entry) => $entry->line->journal_id)
        ->all();

    expect($numbers())->toBe([$first->id, $second->id, $third->id])
        // Asked twice, answered the same way.
        ->and($numbers())->toBe([$first->id, $second->id, $third->id]);
})->note(
    // ⚠ HONEST LIMIT OF THIS TEST: SQLite tends to return rows in insertion
    // order anyway, so this would stay green even if the tiebreaks were
    // deleted. It documents the intent and catches an actively WRONG order
    // (by amount, by id descending); the engine that can genuinely reorder
    // same-date rows is MySQL, and the pre-deploy MySQL run is what proves it.
    'Ordering is asserted here, but only the MySQL run can falsify it.'
);

// ─── C-GLA-3. THE PAGE SEED ─────────────────────────────────────────────────

it('carries the running balance across a page boundary', function () {
    foreach (['100', '200', '300', '400', '500'] as $index => $amount) {
        ($this->post)($amount, '2026-07-0'.($index + 1));
    }

    $pageOne = ($this->ledger)(['perPage' => 2, 'page' => 1]);
    $pageTwo = ($this->ledger)(['perPage' => 2, 'page' => 2]);
    $pageThree = ($this->ledger)(['perPage' => 2, 'page' => 3]);

    expect($pageOne->entries->last()->balance->minor)->toBe(30000)          // 100 + 200
        // ⚠⚠ THE DEFECT THIS CATCHES: restarting from the opening balance would
        // make this 30,000 instead of 60,000, and every figure on page two
        // would be wrong by exactly one page.
        ->and($pageTwo->entries->first()->balance->minor)->toBe(60000)      // + 300
        ->and($pageTwo->entries->last()->balance->minor)->toBe(100000)      // + 400
        ->and($pageThree->entries->first()->balance->minor)->toBe(150000)   // + 500
        ->and($pageOne->pages())->toBe(3)
        ->and($pageOne->total)->toBe(5);
});

it('ends the last page on the closing balance', function () {
    foreach (['100', '200', '300'] as $index => $amount) {
        ($this->post)($amount, '2026-07-0'.($index + 1));
    }

    $last = ($this->ledger)(['perPage' => 2, 'page' => 2]);

    expect($last->entries->last()->balance->minor)->toBe($last->closing()->minor);
});

// ─── C-GLA-4. THE SELF-CHECK ────────────────────────────────────────────────

it('agrees with the trial balance it drills into', function () {
    ($this->post)('1000', '2026-07-05');
    ($this->post)('2500', '2026-08-02');

    $ledger = ($this->ledger)();

    $trialBalance = TrialBalance::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-08-31')->endOfDay(),
    );
    $row = $trialBalance->rows->first(fn ($candidate) => $candidate->account->id === $this->bank->id);

    expect($ledger->hasIndependentCheck())->toBeTrue()
        ->and($ledger->isConsistent())->toBeTrue()
        ->and($ledger->closing()->minor)->toBe($row->closing()->minor)
        ->and($ledger->debits->minor)->toBe($row->debit->minor);
});

// ─── C-GLA-7. THE PARTY FILTER ──────────────────────────────────────────────

it('shows one party s own opening balance and lines, and says it cannot be checked', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);
    $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Bogura Traders']);

    ($this->post)('700', '2026-06-15', $supplier);   // before the window: the party's opening
    ($this->post)('300', '2026-07-10', $supplier);
    ($this->post)('900', '2026-07-11', $other);
    ($this->post)('50', '2026-07-12');               // no party at all

    $filtered = ($this->ledger)([
        'partyType' => $supplier->getMorphClass(),
        'partyId' => $supplier->id,
    ]);

    expect($filtered->opening->minor)->toBe(70000)
        ->and($filtered->entries)->toHaveCount(1)
        ->and($filtered->entries->first()->balance->minor)->toBe(100000)
        ->and($filtered->isFilteredByParty())->toBeTrue()
        // ⚠ Nothing to check against: no trial-balance row describes one party's
        // share of an account, and showing a tick that was never tested would be
        // worse than showing none.
        ->and($filtered->hasIndependentCheck())->toBeFalse();

    // Unfiltered, the same window lists every party's lines and the one with no
    // party at all.
    expect(($this->ledger)()->entries)->toHaveCount(3);
});

it('offers only the parties that actually have postings on the account', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);
    Supplier::create(['business_id' => $this->business->id, 'name' => 'Never Posted Ltd']);

    ($this->post)('300', '2026-07-10', $supplier);

    $parties = AccountLedger::partiesOn(
        $this->bank,
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-08-31')->endOfDay(),
    );

    expect($parties)->toHaveCount(1)
        ->and($parties->first()['label'])->toBe('Sundarban Apiaries');
});

it('ignores a party filter naming something that never posted to this account', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);
    ($this->post)('300', '2026-07-10', $supplier);

    $window = [Carbon::parse('2026-07-01')->startOfDay(), Carbon::parse('2026-08-31')->endOfDay()];

    expect(AccountLedger::resolveParty('Supplier:'.$supplier->id, $this->bank, ...$window))
        ->toBe([$supplier->getMorphClass(), $supplier->id])
        // Not a real filter: unresolvable rather than an error, and certainly
        // not a class name taken from a URL and trusted.
        ->and(AccountLedger::resolveParty('Supplier:9999', $this->bank, ...$window))->toBeNull()
        ->and(AccountLedger::resolveParty('Whatever:1', $this->bank, ...$window))->toBeNull()
        ->and(AccountLedger::resolveParty('nonsense', $this->bank, ...$window))->toBeNull()
        ->and(AccountLedger::resolveParty(null, $this->bank, ...$window))->toBeNull();
});

// ─── THE SCREEN ─────────────────────────────────────────────────────────────

it('asks for an account rather than showing a ledger of everything', function () {
    ($this->post)('1000', '2026-07-05');

    $this->actingAs($this->reader)
        ->get('/accounting/general-ledger')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/general-ledger/index')
            ->where('account', null)
            ->where('entries', [])
            ->where('ledger_empty', false)
        );
});

it('shows the chosen account s postings with a running balance', function () {
    ($this->post)('1000', '2026-07-05');
    ($this->post)('500', '2026-07-06');

    $this->actingAs($this->reader)
        ->get('/accounting/general-ledger?account='.$this->bank->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/general-ledger/index')
            ->where('account.code', $this->bank->code)
            ->where('summary.debits', '1,500.00')
            ->where('summary.closing', '1,500.00')
            ->where('summary.closing_side', 'debit')
            ->where('summary.checked', true)
            ->where('summary.consistent', true)
            ->has('entries', 2)
            ->where('entries.1.balance', '1,500.00')
        );
});

it('keeps the general ledger away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/general-ledger')->assertForbidden();
});

it('rolls a heading up into one row per head beneath it, with the total the statements show', function () {
    // 26 Sep 2026: headings can be made from a voucher now, so the ledger
    // answers "what did this heading come to" instead of refusing a heading.
    $rent = Account::where('code', '6051')->sole();
    $office = Account::where('code', '6050')->sole();
    $operating = Account::where('code', '6000')->sole();
    $spend = function (Account $head, string $amount, string $date) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: [
                JournalLineDraft::debit($head->id, Money::ofTaka($amount)),
                JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount)),
            ],
            memo: 'spend',
        ), $this->reader);
    };

    $spend($rent, '1000', '2026-07-05');   // before the window: opening
    $spend($rent, '250', '2026-08-01');    // in the window
    $spend(Account::where('code', '6052')->sole(), '75', '2026-08-02');

    $page = $this->actingAs($this->reader)
        ->get('/accounting/general-ledger?account='.$office->id.'&from=2026-07-15&to=2026-08-13')
        ->assertOk()
        ->viewData('page')['props'];

    $rows = collect($page['rollup']['rows'])->keyBy('label');

    expect($page['summary'])->toBeNull()
        ->and($page['entries'])->toBe([])
        ->and($rows['6051 - '.$rent->name]['opening'])->toBe('1,000.00 Dr')
        ->and($rows['6051 - '.$rent->name]['debits'])->toBe('250.00')
        ->and($rows['6051 - '.$rent->name]['closing'])->toBe('1,250.00 Dr')
        ->and($page['rollup']['total']['closing'])->toBe('1,325.00 Dr')
        ->and($page['rollup']['total']['debits'])->toBe('325.00');

    // The root heading rolls the whole branch up, grandchildren included.
    $root = $this->actingAs($this->reader)
        ->get('/accounting/general-ledger?account='.$operating->id.'&from=2026-07-15&to=2026-08-13')
        ->assertOk()
        ->viewData('page')['props'];

    expect($root['rollup']['total']['closing'])->toBe('1,325.00 Dr')
        ->and(collect($root['rollup']['rows'])->where('moved', true)->count())->toBe(2);
});

// ─── REVERSALS: KEPT, MARKED, OPTIONALLY HIDDEN IN PAIRS (12 Oct 2026) ──────

/*
 * D (200) is posted before the window and reversed inside it: a half pair.
 * B (500) and its reversal both fall inside the window: a whole pair.
 */
function reversalScenario(object $test): array
{
    $reverse = fn ($journal, string $on) => app(ReverseJournal::class)->handle($journal, $test->reader, Carbon::parse($on));

    $d = ($test->post)('200', '2026-06-20');
    $a = ($test->post)('1000', '2026-07-05');
    $b = ($test->post)('500', '2026-07-06');
    $reversalOfB = $reverse($b, '2026-07-10');
    $reversalOfD = $reverse($d, '2026-07-15');
    $c = ($test->post)('300', '2026-07-20');

    return compact('a', 'b', 'c', 'd', 'reversalOfB', 'reversalOfD');
}

it('keeps reversed entries and their reversals on the ledger by default', function () {
    reversalScenario($this);

    $ledger = ($this->ledger)();

    expect($ledger->entries->map(fn (LedgerEntry $entry) => $entry->balance->minor)->all())
        ->toBe([120000, 170000, 120000, 100000, 130000])
        ->and($ledger->hiddenJournals)->toBe(0);
});

it('hides a reversed pair only when both halves are in the window, and the balances still run true', function () {
    $journals = reversalScenario($this);

    $ledger = AccountLedger::for(
        account: $this->bank,
        from: Carbon::parse('2026-07-01')->startOfDay(),
        to: Carbon::parse('2026-08-31')->endOfDay(),
        hideReversedPairs: true,
    );

    // B and its reversal are gone; D's reversal stays, its original being before the window.
    expect($ledger->entries->map(fn (LedgerEntry $entry) => (int) $entry->line->journal_id)->all())
        ->toBe([$journals['a']->id, $journals['reversalOfD']->id, $journals['c']->id])
        ->and($ledger->entries->map(fn (LedgerEntry $entry) => $entry->balance->minor)->all())
        ->toBe([120000, 100000, 130000])
        ->and($ledger->hiddenJournals)->toBe(2)
        ->and($ledger->closing()->minor)->toBe(130000)
        ->and($ledger->isConsistent())->toBeTrue();

    // The page seed leaves the hidden pair out too.
    $third = AccountLedger::for(
        account: $this->bank,
        from: Carbon::parse('2026-07-01')->startOfDay(),
        to: Carbon::parse('2026-08-31')->endOfDay(),
        perPage: 1,
        page: 3,
        hideReversedPairs: true,
    );

    expect($third->entries->first()->balance->minor)->toBe(130000)
        ->and($third->total)->toBe(3);
});

it('labels a reversed entry and its reversal, and hides the pair from the URL toggle', function () {
    $journals = reversalScenario($this);
    $url = '/accounting/general-ledger?account='.$this->bank->id.'&from=2026-07-01&to=2026-08-31';

    $this->actingAs($this->reader)->get($url)
        ->assertInertia(fn ($page) => $page
            ->has('entries', 5)
            ->where('hide_reversed', false)
            ->where('entries.1.reversed_by.number', $journals['reversalOfB']->journal_number)
            ->where('entries.2.reversal_of.number', $journals['b']->journal_number)
            ->where('entries.3.reversal_of.number', $journals['d']->journal_number)
            ->where('entries.0.reversed_by', null)
            ->where('entries.0.reversal_of', null));

    $this->actingAs($this->reader)->get($url.'&hide_reversed=1')
        ->assertInertia(fn ($page) => $page
            ->has('entries', 3)
            ->where('hide_reversed', true)
            ->where('hidden_journals', 2)
            ->where('entries.1.reversal_of.number', $journals['d']->journal_number)
            ->where('entries.2.balance', '1,300.00')
            ->where('summary.closing', '1,300.00')
            ->where('summary.consistent', true));
});

it('still lists a switched-off head that holds postings, marked off, and not one that never held any (ACC-007)', function () {
    ($this->post)('1000', '2026-07-05');

    $this->capital->update(['is_active' => false]);
    $unused = Account::where('code', '6051')->firstOrFail();
    $unused->update(['is_active' => false]);

    $this->actingAs($this->reader)->get(route('accounting.general-ledger'))
        ->assertInertia(function ($page) use ($unused) {
            $accounts = collect($page->toArray()['props']['accounts']);

            expect($accounts->firstWhere('id', $this->capital->id))->not->toBeNull()
                ->and($accounts->firstWhere('id', $this->capital->id)['inactive'])->toBeTrue()
                ->and($accounts->firstWhere('id', $this->bank->id)['inactive'])->toBeFalse()
                ->and($accounts->firstWhere('id', $unused->id))->toBeNull();
        });
});
