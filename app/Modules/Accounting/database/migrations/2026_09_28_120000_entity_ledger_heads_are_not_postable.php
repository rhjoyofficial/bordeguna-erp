<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠⚠ 28 Sep 2026 — close the entity-ledger-head hole on already-backfilled rows.
 *
 * The 27 Sep feature created a named ledger head for every customer, supplier and
 * employee with `is_postable = true`, on the promise that PostJournal would
 * redirect a posting on the head to its control account + party. That redirect
 * was never built, so a posting landed directly on the head and disappeared from
 * the customer statement, the receivables/payables ageing, the employee statement
 * and the R7 subledger reconciliation. `EntityLedgers` now creates these heads
 * non-postable (a display grouping, not a ledger); this flips the ones already in
 * the chart so the fix reaches production, not only new entities.
 *
 * Targets exactly the entity heads: the accounts referenced by an entity's own
 * `account_id`. Nothing else is touched.
 */
return new class extends Migration
{
    private const ENTITY_TABLES = ['customers', 'suppliers', 'employees'];

    public function up(): void
    {
        foreach (self::ENTITY_TABLES as $table) {
            $headIds = DB::table($table)->whereNotNull('account_id')->pluck('account_id')->all();

            if ($headIds !== []) {
                DB::table('accounts')->whereIn('id', $headIds)->update(['is_postable' => false]);
            }
        }
    }

    /**
     * Restores the pre-fix state honestly (these heads were all postable before).
     * ⚠ Rolling back re-opens the hole this migration closes — do it only to
     * rehearse the migration, never on a live chart.
     */
    public function down(): void
    {
        foreach (self::ENTITY_TABLES as $table) {
            $headIds = DB::table($table)->whereNotNull('account_id')->pluck('account_id')->all();

            if ($headIds !== []) {
                DB::table('accounts')->whereIn('id', $headIds)->update(['is_postable' => true]);
            }
        }
    }
};
