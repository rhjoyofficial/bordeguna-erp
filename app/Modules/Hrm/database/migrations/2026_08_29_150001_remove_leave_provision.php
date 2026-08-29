<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave provisioning leaves the ERP entirely (Joy, 29 August 2026).
 *
 * ⚠⚠ THIS IS A REMOVAL, NOT A SWITCH. Provisioning was already disabled by
 * policy on 23 Aug 2026: the nav link was pulled, the Record path refused, and
 * no journal was ever posted. Joy has now asked for the machinery itself to go,
 * so the table, the screens, the actions, the valuation, the R8 reconciliation
 * check and the 2135 liability head all leave together.
 *
 * ⚠ WHAT IS DELIBERATELY KEPT. Account 6130 Leave Encashment and the
 * `payroll.leave_encashment` mapping STAY. They are where an actual encashment
 * PAYMENT lands if one is ever made by hand, which is a different thing from
 * accruing a liability nobody asked for.
 *
 * ⚠⚠ NOTHING IS LOST. Verified on production before writing this: zero rows in
 * `leave_provision_runs` and zero journal lines against account 2135 in any of
 * the four businesses. The feature was built, disabled, and never used once.
 *
 * ⚠ The 2135 delete is GUARDED. If any journal line ever references the account
 * it is deactivated instead of deleted, because a posted line may not be
 * orphaned. A chart head with history is history; one with none is clutter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('leave_provision_runs');

        DB::table('account_mappings')->where('key', 'payroll.leave_provision')->delete();

        $accounts = DB::table('accounts')->where('code', '2135')->pluck('id');

        foreach ($accounts as $accountId) {
            $posted = DB::table('journal_lines')->where('account_id', $accountId)->exists();

            if ($posted) {
                DB::table('accounts')->where('id', $accountId)->update(['is_active' => false]);

                continue;
            }

            DB::table('accounts')->where('id', $accountId)->delete();
        }
    }

    /**
     * ⚠ Deliberately not reversible. Recreating an empty table and a chart head
     * would restore the shape and not the feature, and the code that filled
     * them is gone. Restore from a backup if this ever has to come back.
     */
    public function down(): void
    {
        // No down path on purpose. See the note above.
    }
};
