<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The asset register and the depreciation run, through the real HTTP layer.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. The entire Assets module loaded MIGRATIONS ONLY -
 * no routes, no controllers, no screens, no permissions. `RecordFixedAsset` and
 * `ChargeDepreciation` were both complete and tested and had no caller anywhere
 * in the application. So a delivery van's only reachable path was Purchasing,
 * which files it as STOCK and expenses it as cost of goods sold the first time
 * it is issued: through one month's P&L and off the balance sheet for ever. And
 * nothing ever wore out, so vehicles would have shown at original cost in every
 * balance sheet the business ever produced.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Asset Co', 'slug' => 'asset-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AssetAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->vehicles = Account::where('code', '1230')->sole();

    $this->payload = fn (array $overrides = []) => array_replace([
        'asset_code' => 'VAN-01',
        'name' => 'Delivery van',
        'account_id' => $this->vehicles->id,
        'acquired_on' => '2026-07-01',
        'cost' => '1200000.00',
        'salvage_value' => '120000.00',
        'useful_life_months' => 36,
        'money_account_id' => $this->bank->id,
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

// ── The register ────────────────────────────────────────────────────────────

it('records an asset through the screen and keeps it off the profit and loss', function () {
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)())
        ->assertRedirect('/assets');

    $asset = FixedAsset::sole();

    expect($asset->asset_code)->toBe('VAN-01')
        ->and($asset->cost)->toBe('1200000.0000');

    // ⚠⚠ Dr Vehicles (an ASSET) · Cr Cash at Bank. Nothing reaches an expense
    // account, which is the entire point: the van is on the balance sheet, not
    // in this month's costs.
    expect((int) JournalLine::where('account_id', $this->vehicles->id)->sum('amount_minor'))->toBe(120000000)
        ->and((int) JournalLine::where('account_id', $this->bank->account_id)->sum('amount_minor'))->toBe(-120000000);

    Journal::all()->each(fn (Journal $journal) => expect($journal->balance()->isZero())->toBeTrue());
});

it('records an asset with no money account and posts nothing', function () {
    // ⚠ Knowing what you own is not an accounting question. An asset paid for
    // outside the ERP still belongs in the register, and the engine deliberately
    // skips the posting - stated here so nobody "fixes" it later.
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['money_account_id' => null]))
        ->assertRedirect();

    expect(FixedAsset::count())->toBe(1)
        ->and(Journal::count())->toBe(0);
});

/**
 * ⚠⚠ FOUND WHILE BUILDING THIS SCREEN. Accumulated Depreciation is an Asset
 * account, postable and active, so every check in the action passed it. Filing
 * a van there puts a DEBIT into the contra-asset that cancels real accumulated
 * depreciation: the trial balance stays at zero and the balance sheet is wrong
 * by twice the asset's value, in the direction that flatters the business.
 */
it('refuses to acquire an asset into an account the software posts to itself', function (string $slug) {
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['account_id' => Account::bySlug($slug)->id]))
        ->assertSessionHasErrors('account_id');

    expect(FixedAsset::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
})->with([
    // ⚠⚠ Each of these was ACCEPTED BY THE ENGINE until 9 August, and each is a
    // different way to make the balance sheet wrong while the trial balance
    // stays perfectly at zero. Found on scratch MySQL, not by the suite.
    'accumulated depreciation - a debit cancels real depreciation' => ['accumulated_depreciation'],
    'stock on hand - the van becomes inventory and is issued as COGS' => ['stock_on_hand'],
    'cash at bank - Dr cash / Cr cash leaves no trace of the purchase' => ['cash_at_bank'],
    'accounts receivable' => ['accounts_receivable'],
]);

it('still allows an ordinary non-current asset account', function () {
    // ⚠ The guard is only correct if the legitimate case still passes. A rule
    // that refuses everything is not a rule, it is an outage.
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['account_id' => Account::where('code', '1210')->sole()->id]))
        ->assertRedirect();

    expect(FixedAsset::count())->toBe(1);
});

it('refuses a non-asset account, a future acquisition and salvage above cost', function (array $overrides, string $field) {
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)($overrides))
        ->assertSessionHasErrors($field);

    expect(FixedAsset::count())->toBe(0);
})->with([
    'expense account' => [fn () => ['account_id' => Account::where('code', '6051')->sole()->id], 'account_id'],
    'future date' => [['acquired_on' => '2026-12-01'], 'acquired_on'],
    'salvage above cost' => [['salvage_value' => '9999999.00'], 'salvage_value'],
    'zero cost' => [['cost' => '0'], 'cost'],
]);

it('refuses a duplicate asset code within the business', function () {
    $this->actingAs($this->owner)->post('/assets', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['name' => 'Another van']))
        ->assertSessionHasErrors('asset_code');

    expect(FixedAsset::count())->toBe(1);
});

it('offers only non-current asset accounts, never cash or accumulated depreciation', function () {
    $this->actingAs($this->owner)
        ->get('/assets')
        ->assertInertia(function (AssertableInertia $page) {
            $labels = collect($page->toArray()['props']['assetAccounts'])->pluck('label')->implode('|');

            // ⚠⚠ "Every postable Asset account" would include Cash at Bank,
            // Accounts Receivable and Stock on Hand - a picker whose choices are
            // overwhelmingly wrong.
            expect($labels)->toContain('1230 - Vehicles')
                ->and($labels)->toContain('1210 - Furniture and Fixtures')
                ->and($labels)->not->toContain('Cash at Bank')
                ->and($labels)->not->toContain('Stock on Hand')
                ->and($labels)->not->toContain('Accumulated Depreciation');
        });
});

// ── Depreciation ────────────────────────────────────────────────────────────

it('charges one month of wear through the screen', function () {
    $this->actingAs($this->owner)->post('/assets', ($this->payload)());

    // July 2026 has finished; the asset was acquired on 1 July.
    $this->actingAs($this->owner)
        ->post('/assets/depreciation', ['year' => 2026, 'month' => 7])
        ->assertRedirect('/assets/depreciation');

    $charge = FixedAssetDepreciation::sole();

    // (1,200,000 - 120,000) / 36 = 30,000 a month.
    expect($charge->amount)->toBe('30000.0000')
        // ⚠ Charged on the LAST DAY of the month it belongs to, not the day the
        // screen was used. Running July's depreciation in August must not put
        // July's cost into August.
        ->and($charge->charged_on->toDateString())->toBe('2026-07-31');

    expect((int) JournalLine::where('account_id', Account::bySlug('depreciation_expense')->id)->sum('amount_minor'))->toBe(3000000)
        // ⚠ A CREDIT to an account that lives under Assets: accumulated
        // depreciation is a contra-asset, so the original cost stays visible
        // beside what has been written off it.
        ->and((int) JournalLine::where('account_id', Account::bySlug('accumulated_depreciation')->id)->sum('amount_minor'))->toBe(-3000000);

    expect((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('is harmless to run the same month twice', function () {
    $this->actingAs($this->owner)->post('/assets', ($this->payload)());

    $this->actingAs($this->owner)->post('/assets/depreciation', ['year' => 2026, 'month' => 7])->assertRedirect();
    $this->actingAs($this->owner)->post('/assets/depreciation', ['year' => 2026, 'month' => 7])->assertRedirect();

    expect(FixedAssetDepreciation::count())->toBe(1)
        ->and((int) JournalLine::where('account_id', Account::bySlug('depreciation_expense')->id)->sum('amount_minor'))->toBe(3000000);
});

it('refuses a month that has not finished, in words about depreciation', function () {
    $this->actingAs($this->owner)->post('/assets', ($this->payload)());

    // ⚠ August 2026 is the month "today" falls in. Charging it now would move
    // cost into a month the business has not lived through.
    $this->actingAs($this->owner)
        ->post('/assets/depreciation', ['year' => 2026, 'month' => 8])
        ->assertSessionHasErrors('period');

    expect(FixedAssetDepreciation::count())->toBe(0);
});

it('offers the last complete month rather than the current one', function () {
    // ⚠ The action refuses an unfinished month, so a form defaulting to the
    // current month would present a default that is always rejected.
    $this->actingAs($this->owner)
        ->get('/assets/depreciation')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('defaultYear', 2026)
            ->where('defaultMonth', 7)
            ->where('hasLedgerMapping', true)
            ->etc());
});

// ── Permission boundaries ───────────────────────────────────────────────────

it('keeps the register away from Staff entirely', function () {
    // ⚠ What the business owns and what it paid is closer to the payroll
    // register than to a stock list. There is no operational task that needs it.
    ($this->asRole)('Staff')->get('/assets')->assertForbidden();
    ($this->asRole)('Staff')->get('/assets/depreciation')->assertForbidden();
    ($this->asRole)('Staff')->post('/assets', ($this->payload)())->assertForbidden();

    expect(FixedAsset::count())->toBe(0);
});

it('lets a Manager record an asset but never run depreciation', function () {
    ($this->asRole)('Manager')->post('/assets', ($this->payload)())->assertRedirect();

    expect(FixedAsset::count())->toBe(1);

    // ⚠⚠ Owner only. One run posts cost against EVERY asset at once, and the
    // idempotence guard makes a second run harmless rather than reversible.
    ($this->asRole)('Manager')
        ->post('/assets/depreciation', ['year' => 2026, 'month' => 7])
        ->assertForbidden();

    expect(FixedAssetDepreciation::count())->toBe(0);
});

it('tells each screen what its viewer may do', function () {
    ($this->asRole)('Manager')
        ->get('/assets')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true)->etc());

    ($this->asRole)('Manager')
        ->get('/assets/depreciation')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canRun', false)->etc());
});

// ── Finance decisions B3 and B4 (29 Sep 2026) ──────────────────────────────

it('takes the useful life from the asset class when none is typed, and keeps the class on the asset', function () {
    $vehicles = AssetCategory::create([
        'business_id' => $this->business->id, 'name' => 'Vehicles', 'code' => 'VEH', 'default_useful_life_months' => 60,
    ]);

    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['useful_life_months' => null, 'asset_category_id' => $vehicles->id]))
        ->assertRedirect('/assets');

    $asset = FixedAsset::sole();

    expect($asset->useful_life_months)->toBe(60)
        ->and($asset->asset_category_id)->toBe($vehicles->id);
});

it('lets a typed life override the class, and asks for one when the class has none', function () {
    $small = AssetCategory::create([
        'business_id' => $this->business->id, 'name' => 'Small tools', 'code' => 'SML',
    ]);

    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['useful_life_months' => null, 'asset_category_id' => $small->id]))
        ->assertSessionHasErrors('useful_life_months');

    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['useful_life_months' => 48, 'asset_category_id' => $small->id]))
        ->assertRedirect('/assets');

    expect(FixedAsset::sole()->useful_life_months)->toBe(48);
});

it('refuses to capitalise a purchase below the capitalisation threshold', function () {
    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['cost' => '9999.99', 'salvage_value' => '0']))
        ->assertSessionHasErrors(['cost' => 'Below the capitalisation threshold of 10,000.00: book it as an expense, or change the threshold in Finance policy.']);

    FinancePolicy::create(['business_id' => $this->business->id, 'capitalisation_threshold_minor' => 500_000]);

    $this->actingAs($this->owner)
        ->post('/assets', ($this->payload)(['cost' => '9999.99', 'salvage_value' => '0']))
        ->assertRedirect('/assets');

    expect(FixedAsset::count())->toBe(1);
});

it('reads the register and the depreciation screen in a fixed number of queries, however many assets (FEA-009)', function () {
    $count = function (string $url): int {
        $this->actingAs($this->owner)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $this->actingAs($this->owner)->post('/assets', ($this->payload)(['asset_code' => 'VAN-00']));
    $this->actingAs($this->owner)->post('/assets/depreciation', ['year' => 2026, 'month' => 7]);
    $register = $count('/assets');
    $screen = $count('/assets/depreciation');

    foreach (range(1, 4) as $i) {
        $this->actingAs($this->owner)->post('/assets', ($this->payload)(['asset_code' => 'VAN-0'.$i, 'name' => 'Van '.$i]));
    }

    expect($count('/assets'))->toBe($register)
        ->and($count('/assets/depreciation'))->toBe($screen);

    // The figures are the same as before: 30,000 charged on the first van.
    $this->actingAs($this->owner)->get('/assets')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('assets.data', fn ($rows) => collect($rows)->firstWhere('asset_code', 'VAN-00')['accumulated'] === '30000.00'));
});

// Recording an asset is a popup over the register since 14 Oct 2026 (the form
// sat open above it). The page carries every list the popup draws, and the
// popup records through the same route under the same money rules.
it('carries what the record-an-asset popup needs on the register, and records through the same route', function () {
    $class = AssetCategory::query()->create(['name' => 'Vehicles', 'code' => 'VEH', 'default_tracking' => 'individual', 'default_useful_life_months' => 60]);

    $this->actingAs($this->owner)->get('/assets')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('assets/index')
            ->where('canManage', true)
            ->where('today', '2026-08-09')
            ->has('cutoverDate')
            ->where('canRecordOpening', true)
            ->where('moneyAccounts.0.id', $this->bank->id)
            ->has('suppliers')
            ->where('categories.0.id', $class->id)
            ->where('categories.0.default_useful_life_months', 60)
            ->where('assetAccounts', fn ($accounts) => collect($accounts)->contains('id', $this->vehicles->id)));

    // Every box the popup draws, blanks as blanks, the life left to the class.
    $this->actingAs($this->owner)->post('/assets', [
        'asset_code' => 'VAN-01', 'name' => 'Delivery van', 'account_id' => (string) $this->vehicles->id,
        'asset_category_id' => (string) $class->id, 'acquired_on' => '2026-07-01', 'cost' => '1200000', 'salvage_value' => '',
        'useful_life_months' => '', 'depreciation_starts_on' => '', 'money_account_id' => (string) $this->bank->id,
        'supplier_id' => '', 'note' => '', 'cost_in_opening_balances' => false,
        'opening_accumulated_depreciation' => '', 'remaining_life_months' => '',
    ])->assertRedirect('/assets')->assertSessionHasNoErrors();

    expect(FixedAsset::sole())
        ->useful_life_months->toBe(60)
        ->asset_category_id->toBe($class->id)
        ->and((int) JournalLine::where('account_id', $this->vehicles->id)->sum('amount_minor'))->toBe(120000000);

    // A Manager records, and so gets the popup; the opening-balance box stays the Owner's.
    ($this->asRole)('Manager')->get('/assets')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true)->where('canRecordOpening', false));
});
