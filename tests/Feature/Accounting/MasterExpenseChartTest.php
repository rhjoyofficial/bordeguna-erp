<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Support\ExpenseCategories;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The accountant's Master Expense Chart of Accounts, 5 September 2026.
 *
 * ⚠⚠ THE PROPERTY THAT MATTERS: the seventeen groups are not a report feature.
 * They are non-postable accounts, and `StatementGrouping::headerFor` turns a
 * non-postable ancestor into a profit and loss section on its own. If somebody
 * later marks a header postable "so it can be used", every account beneath it
 * silently loses its section and the statement quietly reshapes. The first test
 * below is the guard against exactly that.
 *
 * See MASTER-EXPENSE-COA-IMPLEMENTATION-2026-09-05.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-05 10:00:00');

    $this->business = Business::create([
        'name' => 'Chart Co',
        'slug' => 'chart-co',
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
    moneyHead('cash_in_hand');

    $this->post = function (string $debitCode, string $creditCode, string $amount) {
        return app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse('2026-09-05'),
            lines: [
                JournalLineDraft::debit(Account::where('code', $debitCode)->sole()->id, Money::ofTaka($amount)),
                JournalLineDraft::credit(Account::where('code', $creditCode)->sole()->id, Money::ofTaka($amount)),
            ],
        ), $this->founder);
    };
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE STRUCTURE ──────────────────────────────────────────────────────────

it('gives every postable expense account a non-postable header to be grouped under', function () {
    $chart = Account::query()->get()->keyBy('id');

    $orphans = $chart
        ->filter(fn (Account $a) => $a->type === AccountType::Expense && $a->is_postable)
        ->filter(function (Account $account) use ($chart) {
            // The same walk StatementGrouping does: up to the first heading.
            $current = $account;

            for ($depth = 0; $depth < 20; $depth++) {
                $parent = $current->parent_id === null ? null : $chart->get($current->parent_id);

                if ($parent === null) {
                    return true;                 // reached the top with no heading
                }

                if (! $parent->is_postable) {
                    return false;                // found its section
                }

                $current = $parent;
            }

            return true;
        });

    expect($orphans->pluck('code')->all())->toBe([]);
});

it('keeps every group header non-postable, because a postable header would swallow its own section', function () {
    $headers = ['6010', '6050', '6090', '6130', '6170', '6200', '6240', '6290', '6330',
        '6370', '6400', '6430', '6470', '6500', '6550', '6600', '6700', '6800'];

    foreach ($headers as $code) {
        $account = Account::where('code', $code)->sole();

        expect($account->is_postable)->toBeFalse("{$code} {$account->name} must not be postable")
            ->and($account->parent_id)->toBe(Account::where('code', '6000')->sole()->id);
    }
});

it('keeps every code four digits so lexicographic order stays chart order', function () {
    // ⚠ Except a cash, bank or wallet head: 11101-11399, its group's code less the zero, plus two digits (21 Sep 2026).
    $bad = Account::query()->pluck('code')
        ->reject(fn ($code) => preg_match('/^\d{4}$/', (string) $code) === 1)
        ->reject(fn ($code) => preg_match('/^11[123]\d{2}$/', (string) $code) === 1)
        // ⚠ And a head under 1175 Loans & Advances to Others: 11751-11759 (14 Oct 2026).
        ->reject(fn ($code) => preg_match('/^1175\d$/', (string) $code) === 1);

    expect($bad->all())->toBe([]);
});

it('carries every system slug through the renumbering', function () {
    // ⚠ These five moved. A slug that failed to follow its account would break
    // payroll, depreciation or the rounding sink silently, and only in
    // production, because the slug is how the software finds the account.
    expect(Account::bySlug('fuel_and_conveyance')?->code)->toBe('6011')
        ->and(Account::bySlug('salaries_expense')?->code)->toBe('6131')
        ->and(Account::bySlug('leave_encashment')?->code)->toBe('6134')
        ->and(Account::bySlug('depreciation_expense')?->code)->toBe('6710')
        ->and(Account::bySlug('rounding_difference')?->code)->toBe('6810');
});

it('refuses to hold an expense account for anything section 18 forbids', function () {
    // Product purchase, raw material, packaging material, fixed assets and
    // advances are assets. The document is explicit, and the fastest way for
    // this to go wrong is somebody adding a helpful-sounding expense head.
    $forbidden = ['raw material', 'product purchase', 'fixed asset', 'employee advance', 'supplier advance'];

    $offenders = Account::query()
        ->where('type', AccountType::Expense)
        ->get()
        ->filter(fn (Account $a) => collect($forbidden)->contains(fn ($word) => str_contains(mb_strtolower($a->name), $word)));

    expect($offenders->pluck('code')->all())->toBe([]);
});

// ─── WHAT THE STRUCTURE BUYS ────────────────────────────────────────────────

it('prints the document s groups as profit and loss sections without a line of report code', function () {
    ($this->post)('6241', '11101', '5000');      // Facebook advertising, paid cash
    ($this->post)('6011', '11101', '300');       // a rickshaw fare
    ($this->post)('6371', '11101', '1200');      // a courier charge

    $sections = IncomeStatement::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-09-30')->endOfDay(),
    )->operatingExpenses;

    expect($sections->pluck('name')->all())->toBe([
        // Chart order, which is the document's order.
        'Travel and Conveyance',
        'Marketing - Online',
        'Courier and Delivery',
    ]);

    $marketing = $sections->firstWhere('name', 'Marketing - Online');

    expect($marketing->lines)->toHaveCount(1)
        ->and($marketing->lines->first()->account->name)->toBe('Facebook Advertising')
        ->and($marketing->presentedTotal()->format())->toBe('5,000.00');
});

it('separates the two marketing groups, which is the whole reason the document splits them', function () {
    ($this->post)('6241', '11101', '5000');      // online: Facebook
    ($this->post)('6293', '11101', '2000');      // offline: banner and poster

    $sections = IncomeStatement::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-09-30')->endOfDay(),
    )->operatingExpenses;

    expect($sections->pluck('name')->all())->toBe(['Marketing - Online', 'Marketing - Offline'])
        ->and($sections->firstWhere('name', 'Marketing - Online')->presentedTotal()->format())->toBe('5,000.00')
        ->and($sections->firstWhere('name', 'Marketing - Offline')->presentedTotal()->format())->toBe('2,000.00');
});

// ─── THE EMPLOYEE-FACING CATEGORIES ─────────────────────────────────────────

/**
 * ⚠ TWENTY-THREE, NOT THE DOCUMENT'S NINETEEN, and every addition has the same
 * shape: a real spend that could only be filed as "Other Approved Business
 * Expense", which section 19 of that same document says must never be the
 * default. Software Subscription (6056) and ERP and Hosting (6057) on 10 Sep for
 * the hosting bill; Daily Labour (6095) and Loading and Unloading (6096) the
 * same day, when Joy described paying labourers out of his own pocket. All four
 * accounts were already in the accountant's chart - only the employee-facing row
 * was missing.
 */
it('plants the document s categories, each pointing at an account that exists', function () {
    ExpenseCategories::ensureDefaults($this->business->id);

    $categories = ExpenseCategories::active($this->business->id);

    expect($categories)->toHaveCount(23)
        ->and($categories->pluck('name'))->toContain(
            'Software Subscription', 'ERP and Hosting', 'Daily Labour', 'Loading and Unloading',
        );

    foreach ($categories as $category) {
        $described = ExpenseCategories::describe($category['id']);

        expect($described['account_id'])->not->toBeNull()
            ->and(Account::find($described['account_id']))->not->toBeNull("{$category['name']} points at nothing");
    }
});

it('gives every category a Bangla name, because that is the point of the list', function () {
    ExpenseCategories::ensureDefaults($this->business->id);

    $missing = ExpenseCategories::active($this->business->id)
        ->filter(fn ($category) => $category['name_bn'] === null || $category['name_bn'] === '');

    expect($missing->pluck('name')->all())->toBe([]);
});

it('keeps miscellaneous out of the default path, exactly as the document demands', function () {
    ExpenseCategories::ensureDefaults($this->business->id);

    $categories = ExpenseCategories::active($this->business->id);

    // "Miscellaneous must NOT be the default category." Exactly one catch-all,
    // and it is flagged so the approver is told to look twice.
    $exceptions = $categories->filter(fn ($category) => $category['is_exception']);

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions->first()['name'])->toBe('Other Approved Business Expense');

    // And no category may point at the catch-all account except that one.
    $catchAll = Account::where('code', '6601')->sole();

    $pointing = $categories->filter(
        fn ($category) => ExpenseCategories::describe($category['id'])['account_id'] === $catchAll->id
    );

    expect($pointing)->toHaveCount(1);
});

it('does not offer raw material as a claim category, which is section 18 in one line', function () {
    ExpenseCategories::ensureDefaults($this->business->id);

    $names = ExpenseCategories::active($this->business->id)->pluck('name')->map(fn ($n) => mb_strtolower($n));

    expect($names)->not->toContain('purchase of materials')
        ->and($names->filter(fn ($n) => str_contains($n, 'material'))->all())->toBe([]);
});

it('sends inward freight to cost of sales, not to overhead', function () {
    ExpenseCategories::ensureDefaults($this->business->id);

    $freight = ExpenseCategories::active($this->business->id)->firstWhere('name', 'Product Inward Freight');
    $account = Account::find(ExpenseCategories::describe($freight['id'])['account_id']);

    // 5300 sits under 5000 Cost of Sales. Freight to bring goods in belongs in
    // the cost of those goods, so it must not land in the 6000 band.
    expect($account->code)->toBe('5300')
        ->and(str_starts_with($account->code, '6'))->toBeFalse();
});

// ─── THE UPGRADE PATH, NOT THE FRESH INSTALL ────────────────────────────────

/**
 * ⚠⚠ EVERY TEST ABOVE RUNS ON A CHART THE SEEDER PLANTED, so it can only ever
 * describe a fresh installation. Production came the other way: through a
 * rename of accounts that already existed and already had journals against
 * them. The first scratch rehearsal proved the two do not automatically agree -
 * the rename moved 6230 to 6011 and left it still called "Fuel and Conveyance",
 * while a fresh install called the same code "Conveyance".
 *
 * So this puts an account back into its pre-migration shape and runs the real
 * migration over it.
 */
it('gives a renamed account its new name, so an upgraded install matches a fresh one', function () {
    $conveyance = Account::where('code', '6011')->sole();

    // Back to exactly how production looked before today.
    DB::table('accounts')->where('id', $conveyance->id)->update([
        'code' => '6230',
        'name' => 'Fuel and Conveyance',
        'parent_id' => Account::where('code', '6000')->sole()->id,
    ]);

    $migration = require base_path('app/Modules/Accounting/database/migrations/2026_09_05_010000_apply_master_expense_chart_of_accounts.php');
    $migration->up();

    $moved = Account::find($conveyance->id);

    expect($moved->code)->toBe('6011')
        ->and($moved->name)->toBe('Conveyance')
        // ⚠ Same row, so every journal line posted to it still resolves.
        ->and($moved->id)->toBe($conveyance->id)
        ->and($moved->system_slug)->toBe('fuel_and_conveyance')
        ->and(Account::find($moved->parent_id)->code)->toBe('6010');

    // And nothing was left behind at the old code.
    expect(Account::where('code', '6230')->exists())->toBeFalse()
        ->and(Account::where('code', 'like', 'TMP-%')->exists())->toBeFalse();
});
