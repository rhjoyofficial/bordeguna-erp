<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The two statements as SCREENS.
 *
 * ⚠⚠ This project has shipped engine actions with no route and no screen more
 * than once, which is why reachability is asserted separately from the maths.
 * A report nobody can open is not a report.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create([
        'name' => 'Screen Co',
        'slug' => 'screen-co',
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

    $bank = moneyHead('cash_at_bank');
    $capital = Account::bySlug('owners_capital');
    $revenue = Account::bySlug('sales_revenue');
    $rent = Account::where('code', '6051')->first();
    $cogs = Account::where('code', '5100')->first();
    $stock = Account::bySlug('stock_on_hand');

    $post = function (int $debit, int $credit, string $amount) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse('2026-08-01'),
            lines: [
                JournalLineDraft::debit($debit, Money::ofTaka($amount)),
                JournalLineDraft::credit($credit, Money::ofTaka($amount)),
            ],
        ), $this->reader);
    };

    $post($bank->id, $capital->id, '300000');
    $post($stock->id, $capital->id, '80000');
    $post($bank->id, $revenue->id, '120000');
    $post($cogs->id, $stock->id, '50000');
    $post($rent->id, $bank->id, '20000');
});

afterEach(fn () => Carbon::setTestNow());

it('shows the profit and loss with the figures the engine computed', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/income-statement')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/income-statement/index')
            ->where('totals.income', '120,000.00')
            ->where('totals.cost_of_sales', '50,000.00')
            ->where('totals.gross_profit', '70,000.00')
            ->where('totals.operating_expenses', '20,000.00')
            ->where('totals.net_profit', '50,000.00')
            ->where('totals.is_loss', false)
            ->where('empty', false)
        );
});

it('shows the balance sheet balancing, with the unclosed result inside equity', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/balance-sheet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/balance-sheet/index')
            // Bank 300,000 + 120,000 - 20,000 = 400,000; stock 80,000 - 50,000 = 30,000.
            ->where('totals.assets', '430,000.00')
            ->where('totals.equity_accounts', '380,000.00')
            ->where('result.for_the_year', '50,000.00')
            ->where('totals.equity', '430,000.00')
            ->where('totals.liabilities_and_equity', '430,000.00')
            // ⚠⚠ The property the whole slice exists for.
            ->where('totals.balances', true)
            ->where('totals.difference', '0.00')
        );
});

it('honours the date filters rather than always showing the year', function () {
    // A window that ends before the postings were made.
    $this->actingAs($this->reader)
        ->get('/accounting/income-statement?from=2026-07-01&to=2026-07-15')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('empty', true));

    $this->actingAs($this->reader)
        ->get('/accounting/balance-sheet?as_at=2026-07-15')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('empty', true));
});

it('keeps both statements away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/income-statement')->assertForbidden();
    $this->actingAs($this->outsider)->get('/accounting/balance-sheet')->assertForbidden();
});

it('groups accounts under the chart s own headings', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/balance-sheet')
        ->assertOk()
        ->assertInertia(function ($page) {
            $assets = collect($page->toArray()['props']['assets']);

            // 1110/1120/1150 all live under 1100 Current Assets, and the section
            // is the nearest HEADER account, not a fixed depth.
            expect($assets->pluck('code')->all())->toBe(['1100'])
                ->and($assets->first()['name'])->toBe('Current Assets');
        });
});

// F31 (practice run 17 Oct 2026): a group whose only lines are deductions (a
// sales return under 4000 Income) printed +807.50 above a child of (807.50). A
// group total must read the way its children read: in brackets when the group
// as a whole sits on the opposite side from its heading.
it('prints a group made only of deductions in brackets, like its child', function () {
    $bank = moneyHead('cash_at_bank');
    $returns = Account::bySlug('sales_returns');

    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-02'),
        lines: [
            JournalLineDraft::debit($returns->id, Money::ofTaka('807.50')),
            JournalLineDraft::credit($bank->id, Money::ofTaka('807.50')),
        ],
    ), $this->reader);

    $this->actingAs($this->reader)
        ->get('/accounting/income-statement')
        ->assertOk()
        ->assertInertia(function ($page) {
            $income = collect($page->toArray()['props']['income'])->keyBy('code');

            // 4100 Sales Revenue is income on the normal side.
            expect($income['4100']['total'])->toBe('120,000.00')
                ->and($income['4100']['is_contra'])->toBeFalse()
                // 4000 holds only the 4200 return: a deduction, shown as one.
                ->and($income['4000']['total'])->toBe('807.50')
                ->and($income['4000']['is_contra'])->toBeTrue()
                ->and($income['4000']['lines'][0]['is_contra'])->toBeTrue();
        })
        ->assertInertia(fn ($page) => $page->where('totals.income', '119,192.50'));
});
