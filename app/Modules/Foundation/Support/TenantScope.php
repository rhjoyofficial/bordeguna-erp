<?php

namespace App\Modules\Foundation\Support;

use Spatie\Permission\PermissionRegistrar;

/**
 * Enters a tenant, completely.
 *
 * ⚠⚠ **THIS EXISTS BECAUSE TWO THINGS THAT MUST BE SET TOGETHER DRIFTED APART.**
 *
 * Being "in" a business means two separate things are true at once:
 *
 *  1. `BusinessContext` is set, which is what `BusinessScope` reads to add
 *     `where business_id = ?` to every query.
 *  2. Spatie's **permission team id** is set, which is what makes a role lookup
 *     resolve the user's roles *for that business* rather than none at all.
 *
 * `ResolveCurrentBusiness` always did both, and said so in its docblock:
 * "sets it in two places together, deliberately … Keeping these in one place
 * stops them drifting apart." **But queued jobs only ever did the first.**
 *
 * The consequence found on 2026-08-01 was a live ACL-02 bypass. Inside a worker
 * `hasRole('Manager')` returned false because no team was set, so
 * `ReportScope::isDepartmentManagerOnly()` returned false, so
 * `ReportScope::for()` returned `unrestricted()` - and a Department Manager's
 * **queued** report was rendered with no department scoping and emailed to
 * them, while the identical report run inline was correctly scoped.
 *
 * ⚠ It was chosen **by size**: `ReportRunner::shouldQueue()` routes large
 * reports to the queue, so the bypass fired exactly when there was most data to
 * leak. And it was **not deterministic** - `queue:work --max-time=3600` keeps a
 * worker alive for an hour, so the team id could be left over from whichever
 * job ran before.
 *
 * So the rule is now mechanical rather than remembered: **nothing sets one of
 * these without the other.** If a third thing ever becomes part of "being in a
 * business", it belongs here and nowhere else.
 *
 * ℹ Deliberately NOT folded into `BusinessContext::set()`. That class is a
 * plain holder with no framework dependencies, which is what lets it be trusted
 * in tests and in low-level code; teaching it about spatie would invert that.
 */
class TenantScope
{
    /**
     * Enter a business. Safe to call more than once.
     *
     * ⚠ Call this **before** loading any user whose roles you intend to check.
     * Spatie caches the `roles` relation on the model instance, so a User read
     * under the wrong team keeps the wrong answer even after the team changes.
     */
    public static function enter(int $businessId): void
    {
        app(BusinessContext::class)->set($businessId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($businessId);
    }

    /**
     * Leave the tenant entirely.
     *
     * ⚠ Both halves matter. Clearing only the business would leave a stale team
     * id for the next unit of work in a long-lived worker, which is the exact
     * shape of the defect this class was written to end.
     *
     * `BusinessScope` then fails closed with `1 = 0`, which is correct: outside
     * a tenant the honest answer is no rows, never every tenant's rows.
     */
    public static function leave(): void
    {
        app(BusinessContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
