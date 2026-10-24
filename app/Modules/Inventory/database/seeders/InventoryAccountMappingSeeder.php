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

        // ⚠ D-SB-2. Where the difference between the agreed order price and the
        // invoiced price lands. Mapped rather than hard-coded so an accountant
        // can re-point it without a deploy, exactly like every other key here.
        'purchasing.price_variance' => 'purchase_price_variance',

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

        // ⭐ Landed cost, Phase 1 (17 Oct 2026, ChatGPT ruling Q4/Q6). The
        // on-hand share of a capitalised cost is debited to THIS head through
        // the batch revaluation primitive, NEVER to the write-off contra the
        // adjustment types use. Seeded to Stock on Hand itself: the stock
        // reconciliation sums every movement (value_delta included) against
        // inventory.stock_on_hand, so the two keys must point at one head, or
        // the reconciliation must be taught the second (see
        // SubledgerReconciliation::reconcileStockOnHand, which adds it).
        'inventory.landed_cost' => 'stock_on_hand',
        // The consumed share: its own cost-of-sales head, never 5100 (R7).
        'purchasing.landed_cost_consumed' => 'landed_cost_consumed',
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the consumed share of
        // a discount, credited to its own cost-of-sales head. The on-hand share
        // credits inventory.stock_on_hand through the revaluation primitive.
        'purchasing.discount_consumed' => 'purchase_discount_consumed',
        // ⭐ The Tk 10,000 sold-stock rule (24 Oct 2026): the cost difference a
        // correction leaves on goods already sold or used, posted today.
        'inventory.cost_correction_consumed' => 'stock_cost_correction_consumed',

        // ⭐ Owner withdrawal, Phase 2 (17 Oct 2026, ChatGPT ruling Q2, D5).
        // Stock the proprietor takes for personal use is debited HERE, at the
        // reserved batch's cost: Owner's Drawings 3200, the EXISTING head by
        // its slug, never a new equity head and never 3100 Capital. Money he
        // pays in later credits the same head by a receipt voucher, so
        // SubsystemAccounts leaves this one head shared.
        'inventory.owner_withdrawal' => 'owners_drawings',
    ];

    /**
     * The expense heads an EXPENSED landed-cost line posts to, by code: these
     * heads carry no slug (they are the accountant's master chart), so they are
     * found by code. Freight, when expensed by an override, goes to cost of
     * sales (5300); both labour types 6096, travel 6173 Market Visit, a bank
     * charge 6471 (finance expense). `Packaging` and `Other` have no seeded
     * head: the person names one.
     *
     * @var array<string, string>
     */
    private const MAPPINGS_BY_CODE = [
        'purchasing.landed_cost.freight' => '5300',
        'purchasing.landed_cost.labour' => '6096',
        'purchasing.landed_cost.bank_charge' => '6471',
        // ⭐ 18 Oct 2026 (decision D-A): travel and market visits for a
        // purchase are an expense by default, on the purchase-trip head.
        'purchasing.landed_cost.travel' => '6173',
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

            foreach (self::MAPPINGS_BY_CODE as $key => $code) {
                $account = Account::query()->where('code', $code)->first();

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
