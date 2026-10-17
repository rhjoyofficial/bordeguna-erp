<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Facades\DB;

/*
 * ⚠⚠ The live chart is not the seeded chart (rehearsal on a copy of production,
 * 17 Oct 2026). Bor de Guna's chart has Joy's own 5150 "Purchases (Goods Not
 * Stock-Tracked)" (added 12 Oct) and no 6090 warehouse group at all, so no
 * 6096 Loading and Unloading Labour; it does have 6201 Packaging Labour. The
 * first draft of the landed-cost data migration took 5150 over as the
 * consumed-share head and silently skipped the labour mapping. This test
 * rebuilds that chart shape and runs the migration against it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Live Chart Co', 'slug' => 'live-chart-co']);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);

    // The seeded 5160 and the landed-cost mappings are what a FRESH install gets;
    // a live business reached this migration without them.
    DB::table('account_mappings')->where('key', 'like', '%landed_cost%')->delete();
    DB::table('accounts')->where('system_slug', 'landed_cost_consumed')->delete();

    // Joy's 5150, and a chart without 6096 (live has no 6090 group at all).
    DB::table('accounts')->insert([
        'code' => '5150',
        'name' => 'Purchases (Goods Not Stock-Tracked)',
        'type' => 'expense',
        'parent_id' => DB::table('accounts')->where('code', '5000')->value('id'),
        'is_postable' => true,
        'is_active' => true,
        'system_slug' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('accounts')->where('code', '6096')->delete();

    $this->migration = require base_path('app/Modules/Purchasing/database/migrations/2026_10_18_100100_landed_cost_heads_mappings_permissions_and_workflow.php');
});

it('leaves an existing 5150 alone and plants its own head on 5160', function () {
    $this->migration->up();
    $this->migration->up();

    $joys = DB::table('accounts')->where('code', '5150')->first();
    $own = DB::table('accounts')->where('system_slug', 'landed_cost_consumed')->get();

    expect($joys->name)->toBe('Purchases (Goods Not Stock-Tracked)')
        ->and($joys->system_slug)->toBeNull()
        ->and($own)->toHaveCount(1)
        ->and($own->first()->code)->toBe('5160')
        ->and($own->first()->name)->toBe('Landed Cost on Goods Sold');

    $mapped = DB::table('account_mappings')
        ->where('business_id', $this->business->id)
        ->where('key', 'purchasing.landed_cost_consumed')
        ->value('account_id');

    expect($mapped)->toBe((int) $own->first()->id);
});

it('maps labour to 6201 Packaging Labour when the chart has no 6096', function () {
    $this->migration->up();

    $labour = DB::table('account_mappings')
        ->where('business_id', $this->business->id)
        ->where('key', 'purchasing.landed_cost.labour')
        ->value('account_id');

    expect($labour)->toBe((int) DB::table('accounts')->where('code', '6201')->value('id'));
});

it('rolls back only the head it planted, never 5150', function () {
    $this->migration->up();
    $this->migration->down();

    expect(DB::table('accounts')->where('code', '5150')->exists())->toBeTrue()
        ->and(DB::table('accounts')->where('system_slug', 'landed_cost_consumed')->exists())->toBeFalse();
});
