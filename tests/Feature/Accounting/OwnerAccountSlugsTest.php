<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Slice 0, D8: 3200 Owner's Drawings and 2220 Loans from Owners can be found
 * by slug, like their sibling 3100 Owner's Capital always could.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Owner Co', 'slug' => 'owner-co']);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->migration = require base_path('app/Modules/Accounting/database/migrations/2026_10_11_100000_owner_drawings_and_loans_have_slugs.php');
});

it('seeds both owner heads with their slugs', function () {
    expect(Account::bySlug('owners_drawings')?->code)->toBe('3200')
        ->and(Account::bySlug('owner_loans')?->code)->toBe('2220');
});

it('slugs the heads of a live chart seeded before, and takes the slugs back on rollback', function () {
    // A chart seeded before the slugs existed.
    DB::table('accounts')->whereIn('code', ['3200', '2220'])->update(['system_slug' => null]);

    $this->migration->up();
    $this->migration->up();

    expect(Account::bySlug('owners_drawings')?->code)->toBe('3200')
        ->and(Account::bySlug('owner_loans')?->code)->toBe('2220');

    $this->migration->down();

    expect(DB::table('accounts')->where('code', '3200')->value('system_slug'))->toBeNull()
        ->and(DB::table('accounts')->where('code', '2220')->value('system_slug'))->toBeNull()
        // Owner's Capital is not this migration's to touch.
        ->and(Account::bySlug('owners_capital')?->code)->toBe('3100');
});

it('skips a chart that has no such head, and never takes a slug another row holds', function () {
    DB::table('accounts')->whereIn('code', ['3200', '2220'])->update(['system_slug' => null]);
    DB::table('accounts')->where('code', '3200')->delete();
    DB::table('accounts')->where('code', '2210')->update(['system_slug' => 'owner_loans']);

    $this->migration->up();

    expect(DB::table('accounts')->where('system_slug', 'owners_drawings')->exists())->toBeFalse()
        ->and(DB::table('accounts')->where('system_slug', 'owner_loans')->value('code'))->toBe('2210')
        ->and(DB::table('accounts')->where('code', '2220')->value('system_slug'))->toBeNull();
});
