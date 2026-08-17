<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Where stock and purchasing land in the chart, by default.
 *
 * ⚠ `inventory.stock_on_hand` is the ANCHOR: its presence is what switches
 * inventory posting on for a business. Exactly as payroll uses
 * `payroll.net_payable`, and for the same reason - a business that has not set
 * up its ledger must still be able to receive stock.
 *
 * ⚠⚠ The six adjustment types ARE the loss accounts. The discriminator added to
 * `inventory_adjustments` in slice 3 for an entirely different reason turns out
 * to be the mapping key, which is the second time that column has paid for
 * itself.
 */
class InventoryAccountMappingSeeder extends Seeder
{
    /** @var array<string, string> */
    private const MAPPINGS = [
        // The anchor, and the account every stock movement touches.
        'inventory.stock_on_hand' => 'stock_on_hand',

        // Received but not yet invoiced. Without it, stock exists on the
        // balance sheet with no liability behind it and the month does not
        // close.
        'inventory.goods_received_not_invoiced' => 'goods_received_not_invoiced',
        'purchasing.accounts_payable' => 'accounts_payable',

        // Where value LEAVES stock without being sold. All six types default to
        // one loss account; separating damaged from expired is one row each,
        // and the mapping is the right place for that argument to happen.
        'inventory.adjustment.default' => 'inventory_write_off',
        'inventory.adjustment.damaged' => 'inventory_write_off',
        'inventory.adjustment.expired' => 'inventory_write_off',
        'inventory.adjustment.lost_or_stolen' => 'inventory_write_off',
        'inventory.adjustment.stock_count_variance' => 'inventory_write_off',
        'inventory.adjustment.manual_correction' => 'inventory_write_off',
        // ⚠ Internal consumption is stock the business USED, not stock it lost.
        // Defaulted to the same account so nothing is invented, and flagged in
        // the design note as the one an accountant is most likely to re-file to
        // an operating expense.
        'inventory.adjustment.internal_consumption' => 'inventory_write_off',
        // ⚠ A revaluation is a change in what stock is WORTH. It has no
        // quantity, and it is the reason value-only movements exist at all.
        'inventory.adjustment.revaluation' => 'inventory_write_off',

        // ⚠ Production waste EXPENSED by the per-run override (C-MFG-4/5).
        // Absorbed waste - the default - never reaches the ledger at all.
        // Defaulted to the same loss account as the adjustments so nothing is
        // invented; separating "production wastage" is one mapping row, and
        // the mapping is the right place for that argument to happen.
        'inventory.production_wastage' => 'inventory_write_off',

        // ⚠ Where a fraction of a paisa goes when four decimals meet two.
        'accounting.rounding_difference' => 'rounding_difference',
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

        // ⚠ Put the caller back. A seeder that walks every tenant and finishes
        // outside all of them evicts whatever called it.
        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
