<?php

namespace App\Modules\Sales\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may sell, and who may decide what things cost.
 *
 * ⚠ The same split every other module here uses: Staff gets the OPERATIONAL
 * permissions - serve a customer, take the money - and not the ones that commit
 * the business financially. Setting a selling price is a margin decision, and
 * editing the customer master is where a credit limit lives, so both stay with
 * Owner and Manager.
 *
 * ⚠ A counter can still work with no customer-management rights, because the
 * walk-in customer is seeded per business and is the default for a counter sale.
 * That is the reason it exists as a real row.
 *
 * ⚠ `givePermissionTo` is additive. `syncPermissions` would wipe out whatever
 * the Inventory, Purchasing and Accounting seeders already granted this role.
 */
class SalesPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            // ⭐ Initial plan B21 (Joy Q3 = A, 24 Oct 2026): retail sellers on
            // consignment, their hand-overs, reported sales and returns.
            'sales.sellers.manage',
            'sales.customers.manage',
            // ⭐⭐ Plan D11 (Joy's answer 19): an NID scan opens for the Owner
            // and nobody else by default. See HoldsIdentityDocuments.
            'sales.customers.identity.view',
            'sales.prices.manage',
            // ⭐ 18 Oct 2026 (Joy, D-E): set a market price below the batch
            // cost (a perishable markdown), with a reason. Owner and Manager.
            'sales.prices.markdown',
            // C-IMP-4. Loading a whole price list is a manager's act.
            'sales.prices.import',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            // Reports pack (11 Oct 2026): the reports that show cost (margin,
            // delivery economics). Not Staff, who read sales but not what it cost.
            'sales.reports.costs',
            'sales.orders.record',
            'sales.orders.approve',
            // Approve an order over the credit limit, or a distributor's
            // before payment, with a written reason (21 Sep 2026). Owner only.
            'sales.credit.override',
            // ⭐ Grant credit limits and days: the authority's yes on a credit
            // terms request (26 Sep 2026, Q16). Owner only by default; a
            // Manager can be given it in Settings > Roles.
            'sales.credit.approve',
            // Change a price, or give a discount, at the counter, with a
            // reason kept on the invoice (21 Sep 2026). No cap yet: the owner
            // has not set one.
            'sales.price.override',
            'sales.orders.cancel',
            'sales.returns.record',
            'sales.returns.refund',
            // ⚠⚠ SSLCommerz slice P4, Joy 16 September: the OWNER only. By bank
            // or bKash a person sends the money and the ERP records it; through
            // SSLCommerz the ERP itself moves the money.
            'sales.gateway.refund',
            // Courier hub slice 1 (11 Oct 2026): courier credentials and
            // webhooks. Technical, never accounting authority (ChatGPT risk 11).
            'couriers.settings.manage',
            'couriers.parcels.view',
            'couriers.parcels.book',
            // Courier hub slice 5: propose a rate card, and make one the price
            // the ERP estimates with (Owner only).
            'couriers.ratecards.propose',
            'couriers.ratecards.approve',
            // ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.2): credit an
            // invoice, correct or reverse a receipt, reverse a challan, void
            // any counter sale.
            'sales.correct',
            'sales.receipts.correct',
            'sales.deliveries.reverse',
            'sales.counter.void_any',
        ],
        'Manager' => [
            // ⭐ Initial plan B21 (Joy Q3 = A, 24 Oct 2026): retail sellers on
            // consignment, their hand-overs, reported sales and returns.
            'sales.sellers.manage',
            'sales.customers.manage',
            'sales.prices.manage',
            // ⭐ 18 Oct 2026 (Joy, D-E): set a market price below the batch
            // cost (a perishable markdown), with a reason. Owner and Manager.
            'sales.prices.markdown',
            // C-IMP-4. Loading a whole price list is a manager's act.
            'sales.prices.import',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            // Reports pack (11 Oct 2026): the reports that show cost (margin,
            // delivery economics). Not Staff, who read sales but not what it cost.
            'sales.reports.costs',
            'sales.orders.record',
            'sales.orders.approve',
            'sales.orders.cancel',
            'sales.returns.record',
            'sales.returns.refund',
            // ⚠ Staff sell at the list price only: a changed price or a
            // discount is a margin decision, like setting the price itself.
            'sales.price.override',
            'couriers.parcels.view',
            'couriers.parcels.book',
            'couriers.ratecards.propose',
            // ⭐ Corrections wave 1: credit an invoice, reverse a challan, void
            // a counter sale after the shift. Not a receipt: the accountant's.
            'sales.correct',
            'sales.deliveries.reverse',
            'sales.counter.void_any',
        ],
        // ⭐ 26 Sep 2026 (Joy, Q18): the accountant READS the sales side, and
        // records a customer's payment, because the money that arrives at the
        // bank is matched by whoever keeps the books. Not selling, not prices,
        // not credit: those are the trade's decisions.
        'Finance' => [
            'sales.reports.view',
            // Reports pack (11 Oct 2026): the reports that show cost (margin,
            // delivery economics). Not Staff, who read sales but not what it cost.
            'sales.reports.costs',
            'sales.receipt.record',
            // ⭐ Corrections wave 1: the accountant credits an invoice and
            // corrects or reverses a customer receipt (FINAL-PLAN 3.2).
            'sales.correct',
            'sales.receipts.correct',
        ],
        // ⚠ Staff TAKE orders (order-book data entry, the same trust level as
        // sales.sell) but cannot approve them - a staff-created order sits
        // pending until Owner/Manager review (C-SO-1) - and cannot cancel or
        // short-close one: erasing a commitment is a management decision
        // (C-SO-6), the same class as an invoice-level discount.
        //
        // ⚠ Staff RECORD returns too (C-SR-9, Joy: "both") - goods arrive when
        // they arrive - but CASH going back over the counter is Owner/Manager
        // (sales.returns.refund), the same class as cancelling an order.
        'Staff' => [
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.returns.record',
            'couriers.parcels.view',
        ],
    ];

    /**
     * Created, given to no role. Person in Charge is a permission handed to a
     * named person (Joy, 26 Sep 2026): it lifts their counter discount cap
     * to the Manager's.
     */
    private const UNGRANTED = [
        'sales.discount.in_charge',
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->merge(self::UNGRANTED)->unique();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $role = Role::where('business_id', $business->id)->where('name', $roleName)->first();

                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
