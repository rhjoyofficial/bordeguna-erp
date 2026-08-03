<?php

namespace App\Modules\Accounting\Support;

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
     */
    private const OWNED_PREFIXES = ['payroll.', 'inventory.', 'purchasing.', 'assets.'];

    /**
     * @return array<int, string> account id => the key that owns it
     */
    public static function on(Carbon $date): array
    {
        $owned = [];

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
