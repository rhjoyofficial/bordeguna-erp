<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Facades\DB;

/*
 * ⚠⚠ The travel head of a "Travel / market visit" landed-cost line (18 Oct
 * 2026, decision D-A), run against the shapes a live chart can have. The live
 * chart (17 Oct copy of production) has 6173 Market Visit and 6019 Travel and
 * TA; a chart may have neither. An existing head is used and never renamed;
 * only a chart with no travel head at all gets one planted.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Travel Chart Co', 'slug' => 'travel-chart-co']);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);

    // A live business reached this migration without the mapping the seeder now plants.
    DB::table('account_mappings')->where('key', 'purchasing.landed_cost.travel')->delete();

    $this->migration = require base_path('app/Modules/Purchasing/database/migrations/2026_10_18_630100_landed_cost_travel_head_mapping.php');
    $this->mapped = fn () => DB::table('account_mappings')->where('business_id', $this->business->id)->where('key', 'purchasing.landed_cost.travel')->value('account_id');
    $this->idOf = fn (string $code) => (int) DB::table('accounts')->where('code', $code)->value('id');
});

it('maps travel to the existing 6173 Market Visit and plants nothing', function () {
    $accounts = DB::table('accounts')->count();

    $this->migration->up();
    $this->migration->up();

    expect(($this->mapped)())->toBe(($this->idOf)('6173'))
        ->and(DB::table('accounts')->count())->toBe($accounts)
        ->and(DB::table('accounts')->where('code', '6173')->value('name'))->toBe('Market Visit')
        ->and(DB::table('account_mappings')->where('key', 'purchasing.landed_cost.travel')->count())->toBe(1);
});

it('falls back to Travel and TA when the chart has no Market Visit', function () {
    DB::table('accounts')->where('code', '6173')->delete();

    $this->migration->up();

    expect(($this->mapped)())->toBe(($this->idOf)('6019'));
});

it('plants its own head under 6000 only when no travel head exists, and rolls back only that', function () {
    // Every travel-like head inactive: none is suitable.
    DB::table('accounts')->where(fn ($q) => $q->whereIn('name', ['Market Visit', 'Travel and TA', 'Inter-district Travel', 'Business Meeting Travel', 'Conveyance'])
        ->orWhereRaw('LOWER(name) LIKE ?', ['%travel%']))->update(['is_active' => false]);

    $this->migration->up();
    $this->migration->up();

    $planted = DB::table('accounts')->where('system_slug', 'landed_cost_travel')->get();

    expect($planted)->toHaveCount(1)
        ->and($planted->first()->code)->toBe('6020')
        ->and($planted->first()->name)->toBe('Travel and Market Visit')
        ->and((int) $planted->first()->parent_id)->toBe(($this->idOf)('6000'))
        ->and(($this->mapped)())->toBe((int) $planted->first()->id);

    $this->migration->down();

    expect(DB::table('accounts')->where('system_slug', 'landed_cost_travel')->exists())->toBeFalse()
        ->and(DB::table('accounts')->where('code', '6173')->exists())->toBeTrue()
        ->and(($this->mapped)())->toBeNull();
});

it('never re-points a mapping somebody already set', function () {
    DB::table('account_mappings')->insert([
        'business_id' => $this->business->id,
        'key' => 'purchasing.landed_cost.travel',
        'account_id' => ($this->idOf)('6012'),
        'effective_from' => '2000-01-01',
        'note' => 'Set by Finance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();
    $this->migration->down();

    expect(($this->mapped)())->toBe(($this->idOf)('6012'));
});
