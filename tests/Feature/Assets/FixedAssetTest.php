<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\ChargeDepreciation;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixed assets: what the business owns and uses rather than sells.
 *
 * ⚠⚠ R8 of the architecture review: an asset bought through Purchasing becomes
 * STOCK and is expensed as cost of goods sold the moment it is issued - so a
 * delivery van would go through one month's profit and loss and disappear from
 * the balance sheet. This register is what stops that.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Own Co', 'slug' => 'own-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    $this->seed(AssetAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->vehicles = Account::where('code', '1230')->first();
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->balanceOn = fn (string $code) => (int) JournalLine::where(
        'account_id', Account::where('code', $code)->first()->id
    )->sum('amount_minor');

    $this->buyVan = fn (string $cost = '1200000.00', string $salvage = '0', int $months = 36) => app(RecordFixedAsset::class)->handle(
        assetCode: 'FA-0001',
        name: 'Delivery van',
        assetAccount: $this->vehicles,
        acquiredOn: '2026-07-01',
        cost: $cost,
        usefulLifeMonths: $months,
        createdBy: $this->user,
        moneyAccount: $this->bank,
        salvageValue: $salvage,
    );
});

it('keeps a van on the balance sheet instead of through the profit and loss', function () {
    $asset = ($this->buyVan)();

    expect($asset->asset_code)->toBe('FA-0001');

    // Dr Vehicles, Cr the bank. Nothing touched an expense account.
    expect(($this->balanceOn)('1230'))->toBe(120000000)
        ->and(($this->balanceOn)('11201'))->toBe(-120000000)
        ->and(($this->balanceOn)('6710'))->toBe(0);

    expect(Journal::first()->source->is($asset))->toBeTrue();
});

it('charges one month of wear to the profit and loss', function () {
    ($this->buyVan)();

    $result = app(ChargeDepreciation::class)->handle(2026, 7, $this->user);

    // 1,200,000 over 36 months = 33,333.33 a month.
    expect($result['charged'])->toBe(1)
        ->and($result['total']->format())->toBe('33,333.33')
        ->and(($this->balanceOn)('6710'))->toBe(3333333)
        // ⚠ A CREDIT to an account under Assets. Accumulated Depreciation is a
        // contra-asset: the van's original cost stays visible beside what has
        // been written off it.
        ->and(($this->balanceOn)('1290'))->toBe(-3333333)
        // The asset account itself never moves after acquisition.
        ->and(($this->balanceOn)('1230'))->toBe(120000000);
});

// ⚠⚠ Audit 13 Oct: an unmapped expense head used to write the charge row and
// silently skip the journal, so the register and the ledger disagreed and the
// month could never be charged properly afterwards.
it('refuses to charge depreciation with no ledger head, writing nothing', function () {
    ($this->buyVan)();
    AccountMapping::query()->where('key', 'assets.depreciation_expense')->delete();

    expect(fn () => app(ChargeDepreciation::class)->handle(2026, 7, $this->user))
        ->toThrow(ValidationException::class, 'no ledger heads');

    expect(DB::table('fixed_asset_depreciations')->count())->toBe(0)
        ->and(($this->balanceOn)('1290'))->toBe(0);
});

it('charges the same month only once, however many times it is run', function () {
    ($this->buyVan)();

    app(ChargeDepreciation::class)->handle(2026, 7, $this->user);
    $second = app(ChargeDepreciation::class)->handle(2026, 7, $this->user);

    // ⚠ Running the command twice in a month is the obvious way to double a
    // cost nobody is watching.
    expect($second['charged'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and(($this->balanceOn)('6710'))->toBe(3333333);
});

it('dates the charge to the month it belongs to, not the day it was run', function () {
    ($this->buyVan)();

    // Run in August for July.
    app(ChargeDepreciation::class)->handle(2026, 7, $this->user);

    expect(Journal::orderByDesc('id')->first()->journal_date->toDateString())->toBe('2026-07-31');
});

it('writes an asset down to exactly its salvage value and no further', function () {
    // ⚠⚠ THE ROUNDING CASE. 100,000 over 36 months is 2,777.78 with a remainder;
    // charging the rounded figure every month would either leave the asset never
    // quite written off or take it past its salvage value.
    ($this->buyVan)('100000.00', '10000.00', 36);

    // ⚠ Time is moved forward rather than charging future months. A month that
    // has not finished cannot be depreciated - the asset has not worn out yet -
    // and the action refuses it.
    $period = Carbon::create(2026, 7, 1);

    for ($i = 0; $i < 40; $i++) {
        Carbon::setTestNow($period->copy()->endOfMonth()->addDay());
        app(ChargeDepreciation::class)->handle($period->year, $period->month, $this->user);
        $period->addMonth();
    }

    $asset = FixedAsset::first();

    // Exactly the depreciable amount, never a paisa more.
    expect($asset->accumulated()->format())->toBe('90,000.00')
        ->and($asset->remainingToDepreciate()->minor)->toBe(0)
        ->and($asset->isFullyDepreciated())->toBeTrue();

    // And it stops charging once there is nothing left.
    Carbon::setTestNow(Carbon::create(2030, 2, 1));
    $after = app(ChargeDepreciation::class)->handle(2030, 1, $this->user);
    expect($after['charged'])->toBe(0);
});

it('does not depreciate before the asset is put to work', function () {
    app(RecordFixedAsset::class)->handle(
        assetCode: 'FA-0002', name: 'Bottling machine',
        assetAccount: Account::where('code', '1220')->first(),
        acquiredOn: '2026-07-01', cost: '360000.00', usefulLifeMonths: 36,
        createdBy: $this->user, moneyAccount: $this->bank,
        depreciationStartsOn: '2026-09-01',
    );

    // ⚠ Bought in July, commissioned in September. Charging it in July would put
    // cost into a month the business had no use of it.
    expect(app(ChargeDepreciation::class)->handle(2026, 7, $this->user)['charged'])->toBe(0);

    Carbon::setTestNow(Carbon::create(2026, 10, 1));
    expect(app(ChargeDepreciation::class)->handle(2026, 9, $this->user)['charged'])->toBe(1);
});

it('refuses to file an asset anywhere but an asset account', function () {
    // Without this a van could be filed under Rent and the balance sheet would
    // be short by its value while the books still balanced perfectly.
    expect(fn () => app(RecordFixedAsset::class)->handle(
        assetCode: 'FA-0003', name: 'Van',
        assetAccount: Account::where('code', '6051')->first(),
        acquiredOn: '2026-07-01', cost: '100', usefulLifeMonths: 12,
        createdBy: $this->user, moneyAccount: $this->bank,
    ))->toThrow(ValidationException::class, 'not an asset account');
});

it('refuses a salvage value above the cost', function () {
    // Otherwise the depreciable amount is negative and the asset earns money by
    // ageing.
    expect(fn () => ($this->buyVan)('50000.00', '60000.00'))
        ->toThrow(ValidationException::class, 'cannot be more than');
});

it('leaves the books balancing through acquisition and depreciation', function () {
    ($this->buyVan)();
    app(ChargeDepreciation::class)->handle(2026, 7, $this->user);

    Carbon::setTestNow(Carbon::create(2026, 9, 1));
    app(ChargeDepreciation::class)->handle(2026, 8, $this->user);

    expect((int) JournalLine::sum('amount_minor'))->toBe(0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();

    // Net book value: cost less two months of wear.
    $net = Money::ofMinor(($this->balanceOn)('1230') + ($this->balanceOn)('1290'));
    expect($net->format())->toBe('1,133,333.34');
});
