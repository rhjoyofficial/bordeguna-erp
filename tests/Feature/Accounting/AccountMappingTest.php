<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;

/**
 * Phase 3 of Accounting: where a business event lands in the chart.
 *
 * ⚠⚠ VERSIONED BY EFFECTIVE DATE, and that is the whole reason this is a table
 * rather than a column. A July journal must resolve against July's mapping, or
 * re-filing an account in December silently rewrites what every earlier report
 * meant - and no two reports would even disagree, because they would all move
 * together.
 *
 * See ACCOUNTING-PHASE3-INTEGRATION.md §2.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Map Co', 'slug' => 'map-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->salaries = Account::bySlug('salaries_expense');
    $this->conveyance = Account::bySlug('fuel_and_conveyance');
});

it('resolves a key to the account in force on that date', function () {
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-01-01'));

    expect(AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-07-15'))->id)
        ->toBe($this->salaries->id);
});

it('keeps posting an old month to the account that was in force then', function () {
    // ⚠⚠ THE POINT OF THE WHOLE TABLE. Basic pay was filed under Salaries all
    // year; from 1 August the accountant re-files it. July's journal must still
    // resolve to Salaries, or last month's report changes when nobody touched
    // last month.
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-01-01'));
    AccountMapping::assign('payroll.earning.basic', $this->conveyance, Carbon::parse('2026-08-01'));

    expect(AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-07-31'))->id)->toBe($this->salaries->id)
        ->and(AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-08-01'))->id)->toBe($this->conveyance->id)
        ->and(AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-09-10'))->id)->toBe($this->conveyance->id);
});

it('supersedes rather than edits, so two rows can never claim one day', function () {
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-01-01'));
    AccountMapping::assign('payroll.earning.basic', $this->conveyance, Carbon::parse('2026-08-01'));

    $rows = AccountMapping::where('key', 'payroll.earning.basic')->orderBy('effective_from')->get();

    // ⚠ The old row is closed on the day before the new one starts, so overlaps
    // are impossible BY CONSTRUCTION. An overlap would otherwise resolve to
    // whichever row the database happened to return first.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-07-31')
        ->and($rows[1]->effective_to)->toBeNull();
});

it('does not shred its history when the same mapping is assigned again', function () {
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-01-01'));
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-08-01'));
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-09-01'));

    // Re-pointing a key at the account it already has is a no-op, so a seeder
    // can run every deploy without turning the history into one row per run.
    expect(AccountMapping::where('key', 'payroll.earning.basic')->count())->toBe(1);
});

it('falls back to a general key when the specific one is not mapped', function () {
    AccountMapping::assign('payroll.earning.default', $this->salaries, Carbon::parse('2026-01-01'));

    // ⚠ The fallback is a SECOND KEY, not a second row type - so it is as dated
    // and as visible in the table as anything else.
    expect(AccountMapping::resolve('payroll.earning.house_rent', Carbon::parse('2026-07-15'), 'payroll.earning.default')->id)
        ->toBe($this->salaries->id);
});

it('throws rather than guessing when nothing is mapped', function () {
    // ⚠⚠ A posting that cannot be mapped STOPS the business event. It does not
    // land in a suspense account and wait to be noticed: the entire value of an
    // automatic accrual is that it is complete.
    expect(fn () => AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-07-15')))
        ->toThrow(MappingNotFound::class, 'payroll.earning.basic');
});

it('throws for a date before the mapping ever existed', function () {
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-08-01'));

    // A mapping that starts today cannot post last month's payroll, and finding
    // that out loudly is better than finding it out at year end.
    expect(fn () => AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-07-15')))
        ->toThrow(MappingNotFound::class);
});

it('keeps one business mappings out of another', function () {
    AccountMapping::assign('payroll.earning.basic', $this->salaries, Carbon::parse('2026-01-01'));

    $other = Business::create(['name' => 'Other Map', 'slug' => 'other-map', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);

    // ⚠ business_id is NOT NULL and there is no installation-wide default row.
    // A nullable business with overrides reads nicely and fails softly - an
    // override with a wrong date silently falls back and posts to the wrong
    // account. One row per business means an unconfigured business fails loudly.
    expect(fn () => AccountMapping::resolve('payroll.earning.basic', Carbon::parse('2026-07-15')))
        ->toThrow(MappingNotFound::class);
});
