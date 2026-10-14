<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\ChargeDepreciation;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FEA-003 / FEA-004 (owner decision 14 Oct 2026): an OPENING fixed asset, one
 * the business owned before the ERP cutover.
 *
 * Its values sit in the accountant's opening trial balance, so recording it
 * posts no journal and the period lock has nothing to protect. The register
 * carries the accumulated depreciation at cutover and the remaining life, and
 * monthly depreciation afterwards spreads the net value over what is left.
 * Both styles of opening TB work: cost with accumulated depreciation, or the
 * net book value alone (cost = NBV, accumulated 0).
 */
beforeEach(function () {
    Carbon::setTestNow('2027-04-10 10:00:00');
    config(['accounting.cutover_date' => '2026-09-01']);

    $this->business = Business::create(['name' => 'Opening Co', 'slug' => 'opening-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AssetAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->person = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->owner = ($this->person)('Owner');

    // The books are closed through August 2026, the month before cutover.
    AccountingPeriod::create(['business_id' => $this->business->id, 'closed_through' => '2026-08-31', 'closed_by' => $this->owner->id]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $this->equipment = Account::where('code', '1230')->sole();

    $this->opening = fn (array $overrides = [], ?User $by = null) => app(RecordFixedAsset::class)->handle(...array_replace([
        'assetCode' => 'FA-OLD',
        'name' => 'Laptop bought in 2024',
        'assetAccount' => $this->equipment,
        'acquiredOn' => '2024-03-01',
        'cost' => '120000',
        'usefulLifeMonths' => 36,
        'createdBy' => $by ?? $this->owner,
        'costInOpeningBalances' => true,
    ], $overrides));

    $this->chargeThrough = function (FixedAsset $asset, array $months): array {
        return array_map(function (array $month) use ($asset) {
            app(ChargeDepreciation::class)->handle($month[0], $month[1], $this->owner);

            $amount = $asset->depreciations()->where('year', $month[0])->where('month', $month[1])->value('amount');

            return $amount === null ? null : Money::ofTaka((string) $amount)->toTaka();
        }, $months);
    };

    $this->months = [[2026, 9], [2026, 10], [2026, 11], [2026, 12], [2027, 1], [2027, 2], [2027, 3]];
});

afterEach(fn () => Carbon::setTestNow());

// FEA-003: the lock protects journals, and an opening asset posts none.
it('records an opening asset bought long before a closed period, and still refuses a paid asset inside it (FEA-003)', function () {
    $asset = ($this->opening)();

    expect($asset->cost_on_books)->toBe(FixedAsset::COST_IN_OPENING_BALANCES)
        ->and($asset->acquired_on->toDateString())->toBe('2024-03-01')
        ->and(Journal::query()->count())->toBe(0);

    expect(fn () => ($this->opening)([
        'assetCode' => 'FA-NEW', 'acquiredOn' => '2026-08-15', 'costInOpeningBalances' => false, 'moneyAccount' => $this->bank,
    ]))->toThrow(PeriodClosedException::class);
});

it('lets only the Owner record an opening asset, dated on or before the cutover (FEA-003)', function () {
    expect(fn () => ($this->opening)([], ($this->person)('Manager')))
        ->toThrow(ValidationException::class, 'Only the Owner');

    expect(fn () => ($this->opening)(['acquiredOn' => '2026-09-02']))
        ->toThrow(ValidationException::class, 'on or before the cutover');

    expect(fn () => ($this->opening)(['openingAccumulated' => '120000.01']))
        ->toThrow(ValidationException::class, 'more than its cost less salvage');
});

// FEA-004, the gross trial balance: cost 120,000 and accumulated 100,000.
it('depreciates a gross opening asset over its remaining life from its net value, then stops (FEA-004)', function () {
    $asset = ($this->opening)(['openingAccumulated' => '100000', 'remainingLifeMonths' => 6]);

    expect($asset->accumulated()->toTaka())->toBe('100000.00')
        ->and($asset->monthlyCharge()->toTaka())->toBe('3333.33')
        ->and($asset->depreciation_starts_on->toDateString())->toBe('2026-09-01')
        // Nothing is charged into the months the opening figure already covers.
        ->and($asset->isDepreciableIn(2026, 8))->toBeFalse();

    expect(($this->chargeThrough)($asset, $this->months))
        ->toBe(['3333.33', '3333.33', '3333.33', '3333.33', '3333.33', '3333.35', null]);

    $asset = $asset->fresh();

    expect($asset->accumulated()->toTaka())->toBe('120000.00')
        ->and($asset->isFullyDepreciated())->toBeTrue()
        // The ledger holds only the post-cutover charges; the opening 100,000
        // is in the accountant's opening trial balance.
        ->and((int) JournalLine::where('account_id', Account::bySlug('accumulated_depreciation')->id)->sum('amount_minor'))->toBe(-2000000);
});

// FEA-004, the net trial balance: the accountant gave only the net book value.
it('depreciates a net-book-value opening asset the same way (FEA-004)', function () {
    $asset = ($this->opening)(['cost' => '20000', 'remainingLifeMonths' => 6]);

    expect($asset->accumulated()->toTaka())->toBe('0.00')
        ->and($asset->monthlyCharge()->toTaka())->toBe('3333.33');

    expect(($this->chargeThrough)($asset, $this->months))
        ->toBe(['3333.33', '3333.33', '3333.33', '3333.33', '3333.33', '3333.35', null]);
});

it('works out the remaining life from the age at cutover when none is given (FEA-004)', function () {
    // Bought March 2024 with a 36-month life: 30 months gone by September 2026.
    $asset = ($this->opening)(['openingAccumulated' => '100000']);

    expect($asset->remaining_life_months)->toBe(6)
        ->and($asset->monthlyCharge()->toTaka())->toBe('3333.33');
});

it('shows the opening accumulated depreciation on the register screen (FEA-004)', function () {
    $this->actingAs($this->owner)->post('/assets', [
        'asset_code' => 'FA-SCREEN',
        'name' => 'Old delivery van',
        'account_id' => $this->equipment->id,
        'acquired_on' => '2023-09-01',
        'cost' => '1200000',
        'useful_life_months' => 60,
        'cost_in_opening_balances' => true,
        'opening_accumulated_depreciation' => '720000',
        'remaining_life_months' => 24,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $row = $this->actingAs($this->owner)->get('/assets')->assertOk()
        ->original->getData()['page']['props']['assets']['data'][0];

    expect($row['opening_accumulated'])->toBe('720000.00')
        ->and($row['accumulated'])->toBe('720000.00')
        ->and($row['book_value'])->toBe('480000.00')
        ->and($row['remaining_life_months'])->toBe(24)
        ->and($row['monthly_charge'])->toBe('20000.00');
});
