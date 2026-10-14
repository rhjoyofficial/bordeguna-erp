<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use Illuminate\Support\Carbon;

/**
 * Sales slice 1: where a sale lands in the chart.
 *
 * See SALES-PHASE1-DESIGN.md §4.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ledger Co', 'slug' => 'sales-ledger-co']);
    app(BusinessContext::class)->set($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);

    $this->on = Carbon::parse('2026-08-04');
});

// ⚠⚠ The mirror of Goods Received Not Invoiced. Without it, cost lands in the
// month the goods left and revenue lands in the month the invoice was raised,
// so every gross margin is drawn from two different periods.
it('adds the Goods Delivered Not Invoiced account as a current asset', function () {
    $account = Account::bySlug('goods_delivered_not_invoiced');

    expect($account)->not->toBeNull()
        ->and($account->code)->toBe('1155')
        ->and($account->name)->toBe('Goods Delivered Not Invoiced')
        ->and($account->is_postable)->toBeTrue();
});

// ⚠ A new code rather than a slug on 4200: this seeder is firstOrCreate on the
// code, so a slug added to an existing row would never reach an installation
// that has already run - and production has.
it('adds Discounts Allowed as its own account under Income', function () {
    $account = Account::bySlug('discounts_allowed');

    expect($account)->not->toBeNull()
        ->and($account->code)->toBe('4210')
        ->and($account->is_postable)->toBeTrue();

    // ⚠ Phase 4 arrived (2026-08-12): 4200 now carries the sales_returns slug,
    // planted by the seeder's set-when-absent branch so installations seeded
    // before it existed pick it up on the next seed run.
    expect(Account::where('code', '4200')->first()->system_slug)->toBe('sales_returns');
});

it('sorts the two new accounts into the right place in the chart', function () {
    $codes = Account::query()->inChartOrder()->pluck('code')->all();

    $gdni = array_search('1155', $codes, true);
    $stock = array_search('1150', $codes, true);
    $advances = array_search('1160', $codes, true);

    expect($gdni)->toBeGreaterThan($stock)
        ->and($gdni)->toBeLessThan($advances);
});

it('maps every key a sale posts to', function () {
    $expected = [
        'sales.revenue' => '4101',
        'sales.accounts_receivable' => '1140',
        'sales.cost_of_goods_sold' => '5100',
        'sales.goods_delivered_not_invoiced' => '1155',
        'sales.discounts_allowed' => '4210',
        'sales.sales_returns' => '4200',
    ];

    foreach ($expected as $key => $code) {
        expect(AccountMapping::resolve($key, $this->on)->code)->toBe($code);
    }
});

// ⚠⚠ THE ANCHOR. The standing rule settled on 3 August: an accounting feature
// must never be able to stop an operational process. A shop whose ledger is not
// configured must still be able to serve a customer.
it('makes sales.revenue the anchor that switches posting on', function () {
    $unconfigured = Business::create(['name' => 'No Ledger Co', 'slug' => 'no-ledger-co']);
    app(BusinessContext::class)->set($unconfigured->id);

    expect(AccountMapping::forKeyOn('sales.revenue', $this->on))->toBeNull();
});

// ⚠ Re-pointing a key at the account it already has must be a no-op, or a
// seeder that runs on every deploy shreds the history into one row per run.
it('does not multiply mapping rows when it runs again', function () {
    (new SalesAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);

    expect(AccountMapping::where('key', 'sales.revenue')->count())->toBe(1);
});

it('maps sales for every business, not just the one in context', function () {
    $second = Business::create(['name' => 'Second Ledger Co', 'slug' => 'second-ledger-co']);

    (new SalesAccountMappingSeeder)->run();

    app(BusinessContext::class)->set($second->id);

    expect(AccountMapping::resolve('sales.revenue', $this->on)->code)->toBe('4101');
});
