<?php

namespace App\Modules\Assets\database\seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Where depreciation lands.
 *
 * ⚠ Only two keys: the asset's own account is named on the asset record, for
 * the same reason an expense voucher names its expense account. What the
 * mapping decides is the pair every asset shares.
 */
class AssetAccountMappingSeeder extends Seeder
{
    /** @var array<string, string> */
    private const MAPPINGS = [
        'assets.depreciation_expense' => 'depreciation_expense',
        // ⚠ A contra-asset: it lives under Assets and carries a credit balance,
        // so the original cost stays visible beside what has been written off.
        'assets.accumulated_depreciation' => 'accumulated_depreciation',
    ];

    public function run(): void
    {
        $from = Carbon::parse('2000-01-01');
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::MAPPINGS as $key => $slug) {
                $account = Account::bySlug($slug);

                if ($account !== null) {
                    AccountMapping::assign($key, $account, $from, 'Seeded default');
                }
            }
        }

        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
