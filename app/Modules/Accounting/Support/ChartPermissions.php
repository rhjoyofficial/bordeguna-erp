<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The permissions that change the chart of accounts, split by how much damage
 * each can do (26 Sep 2026, ledger-heads plan D1, after ChatGPT's review).
 *
 * Until now one key, `accounting.chart.manage`, gated head CRUD AND Bank & cash
 * accounts AND the VAT switch. Joy's accountant needs to add a ledger head in
 * passing; handing him that one key would also have let him switch VAT on.
 *
 *   accounting.chart.head.create     a head, or a sub-heading under an EXISTING heading
 *   accounting.chart.head.update     rename a head, edit its note, deactivate a head
 *   accounting.chart.head.delete     delete an unused, non-system head
 *   accounting.chart.structure.manage  a root heading, re-parenting, re-typing,
 *                                    turning a head into a heading or back,
 *                                    deactivating a heading, a system head's code
 *   accounting.money_account.manage  Bank & cash accounts (each makes its own ledger)
 *   accounting.vat.manage            VAT settings, rates, filing a return
 *   accounting.policy.manage         Finance policy: limits, caps, thresholds (29 Sep 2026)
 *
 * ⚠ `accounting.chart.manage` is NOT deleted. Every role that holds it keeps
 * every power above through the gate hook in {@see self::register()}, so nothing
 * an Owner could do yesterday is refused today, and the period-close and budget
 * screens that still borrow it are untouched. New grants go to the split keys.
 */
final class ChartPermissions
{
    public const HEAD_CREATE = 'accounting.chart.head.create';

    public const HEAD_UPDATE = 'accounting.chart.head.update';

    public const HEAD_DELETE = 'accounting.chart.head.delete';

    public const STRUCTURE = 'accounting.chart.structure.manage';

    public const MONEY_ACCOUNTS = 'accounting.money_account.manage';

    public const VAT = 'accounting.vat.manage';

    public const POLICY = 'accounting.policy.manage';

    /** The old all-in-one key, kept as an alias for everything below. */
    public const LEGACY = 'accounting.chart.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HEAD_CREATE, self::HEAD_UPDATE, self::HEAD_DELETE, self::STRUCTURE, self::MONEY_ACCOUNTS, self::VAT, self::POLICY];
    }

    /** The three that show the chart form at all. @return list<string> */
    public static function anyHead(): array
    {
        return [self::HEAD_CREATE, self::HEAD_UPDATE, self::HEAD_DELETE];
    }

    /**
     * Holders of the legacy key pass every split check.
     *
     * ⚠ Only the six keys above are aliased, and `can(LEGACY)` inside the hook
     * asks the gate for a key that is not in the list, so it falls through to
     * the permission tables and cannot recurse. Returning null lets the split
     * key's own grant decide for everyone else.
     */
    public static function register(): void
    {
        Gate::before(function (Authenticatable $user, string $ability) {
            if (! in_array($ability, self::all(), true)) {
                return null;
            }

            return method_exists($user, 'can') && $user->can(self::LEGACY) ? true : null;
        });
    }
}
