<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Carbon;

/**
 * Which accounts an operational module owns.
 *
 * ⚠⚠ THE PRINCIPLE THIS ENFORCES: every financial movement originates from a
 * business event, and users never create accounting entries for Payroll,
 * Inventory, Purchasing or Fixed Assets by hand. Until now that was a rule
 * everybody agreed with and nothing checked - and the way such a rule dies is
 * somebody "just fixing" a wages figure with a manual journal, after which no
 * reconciliation of that account can ever be conclusive again.
 *
 * ⚠ DERIVED FROM THE MAPPINGS, never a flag on the account. An
 * `is_system_controlled` column would be a second source of truth that drifts
 * the moment somebody re-maps a key, and the mapping already knows the answer:
 * if a subsystem is configured to post there, the subsystem owns it.
 *
 * ⚠ Resolved AS AT A DATE, like everything else built on mappings. An account
 * the subsystem owned in July is protected for a July-dated journal even if it
 * has since been re-mapped.
 */
final class SubsystemAccounts
{
    /**
     * Mapping keys beginning with these belong to an operational module.
     *
     * ⚠ `accounting.` is deliberately absent. Rounding Differences is the
     * ledger's own account, not a subsystem's, and a correction posted there by
     * hand is exactly what it is for.
     *
     * ⚠⚠ `sales.` added 2026-08-04 with Sales slice 2. Without it, Sales Revenue,
     * Accounts Receivable, Cost of Goods Sold, Goods Delivered Not Invoiced and
     * Discounts Allowed were the ONLY module-maintained accounts a manual
     * journal could still touch - and revenue is the single figure a business is
     * most tempted to "just adjust" at month end. Adding a module means adding
     * its prefix here; nothing does that automatically.
     */
    private const OWNED_PREFIXES = ['payroll.', 'inventory.', 'purchasing.', 'assets.', 'sales.'];

    /**
     * @return array<int, string> account id => the key that owns it
     */
    public static function on(Carbon $date): array
    {
        $owned = [];
        $keysByAccount = [];
        $accountTypes = [];
        $accountParents = [];

        foreach (AccountMapping::query()->with('account')->get() as $mapping) {
            if (! self::isOwnedKey($mapping->key)) {
                continue;
            }

            // Only mappings actually in force on the date in question.
            if ($mapping->effective_from->greaterThan($date)) {
                continue;
            }

            if ($mapping->effective_to !== null && $mapping->effective_to->lessThan($date)) {
                continue;
            }

            $owned[$mapping->account_id] = $mapping->key;
            $accountTypes[$mapping->account_id] = $mapping->account?->type;
            $accountParents[$mapping->account_id] = $mapping->account?->parent_id;
            $keysByAccount[$mapping->account_id][] = $mapping->key;
        }

        // ⚠⚠ A GENERAL EXPENSE HEAD THAT PAYROLL ONLY ALLOCATES TO IS SHARED,
        // NOT OWNED. TA/DA and reimbursements are mapped to 6011 Conveyance so
        // that travel money lands where travel is reported - and that one
        // mapping made Conveyance unpayable by voucher or journal (found on the
        // voucher rehearsal, 19 Sep 2026), although every rickshaw fare the
        // business pays belongs there too. Payroll still owns the wage head
        // itself (whatever `payroll.earning.default` names), its payables and
        // employee advances: only an EXPENSE account reached by nothing but
        // `payroll.earning.<component>` keys, and not the wage head, is freed.
        $wageAccountIds = [];

        foreach ($keysByAccount as $accountId => $keys) {
            if (in_array('payroll.earning.default', $keys, true)) {
                $wageAccountIds[$accountId] = true;
            }
        }

        $wageParents = array_values(array_filter(array_map(fn (int $id) => $accountParents[$id] ?? null, array_keys($wageAccountIds))));

        foreach ($keysByAccount as $accountId => $keys) {
            $onlyEarningComponents = array_filter($keys, fn (string $key) => ! str_starts_with($key, 'payroll.earning.')) === [];

            // ⚠ Overtime and Bonus sit in the wage head's OWN group (6130
            // Employee and Staff) and are payroll's as much as Salaries is: only
            // payroll knows the hours and the festival. A head in another group
            // that payroll merely allocates to (Conveyance) is what is shared.
            $inWageGroup = in_array($accountParents[$accountId] ?? null, $wageParents, true);

            if ($onlyEarningComponents
                && ! isset($wageAccountIds[$accountId])
                && ! $inWageGroup
                && ($accountTypes[$accountId] ?? null) === AccountType::Expense) {
                unset($owned[$accountId]);
            }

            // ⭐ Landed cost, Phase 1 (17 Oct 2026): the typed expense heads an
            // EXPENSED landed-cost line posts to (5300 Freight and Carriage
            // Inward, 6096 Loading and Unloading Labour, 6471 Bank Charge) are
            // the same heads an expense voucher and an expense claim have
            // always posted (Product Inward Freight, Loading and Unloading,
            // Bank and MFS Charge). Mapping them for landed cost must not take
            // them away from everybody else, so a head reached by nothing but
            // `purchasing.landed_cost.<type>` keys is SHARED, exactly like a
            // head payroll merely allocates to. The feature's OWN head, 5150
            // (`purchasing.landed_cost_consumed`, no dot), stays owned.
            $onlyLandedCostTypes = array_filter($keys, fn (string $key) => ! str_starts_with($key, 'purchasing.landed_cost.')) === [];

            if ($onlyLandedCostTypes && ($accountTypes[$accountId] ?? null) === AccountType::Expense) {
                unset($owned[$accountId]);
            }

            // ⭐ Owner withdrawal, Phase 2 (17 Oct 2026, ruling Q2): the goods
            // the proprietor takes are debited to Owner's Drawings 3200 by
            // `inventory.owner_withdrawal`, and the money he pays in later is
            // CREDITED to the same head by an ordinary receipt voucher (the
            // ruling: "record that receipt as an offset against drawings, not
            // sales revenue"). Owning the head would refuse exactly that
            // voucher, so an EQUITY head reached by nothing but this key stays
            // shared. Stock on Hand, reached by inventory.stock_on_hand as
            // well, stays owned.
            $onlyOwnerWithdrawal = array_filter($keys, fn (string $key) => $key !== 'inventory.owner_withdrawal') === [];

            if ($onlyOwnerWithdrawal && ($accountTypes[$accountId] ?? null) === AccountType::Equity) {
                unset($owned[$accountId]);
            }
        }

        return $owned;
    }

    private static function isOwnedKey(string $key): bool
    {
        foreach (self::OWNED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
