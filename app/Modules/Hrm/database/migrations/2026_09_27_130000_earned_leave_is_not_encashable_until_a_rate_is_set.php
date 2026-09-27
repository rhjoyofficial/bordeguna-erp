<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ 27 Sep 2026: earned leave is NOT encashable by default (HRM audit + the
 * accountant meeting, 19 Sep: leave provisioning was removed and encashment is
 * dormant, with no rate set).
 *
 * The seed shipped earned leave as encashable, which contradicts that policy AND
 * makes the final-settlement worksheet refuse to total (it will not guess an
 * encashment rate). Turning it off matches the policy and lets settlement
 * compute. When the owner sets an encashment rate, this is re-enabled from the
 * leave-type settings; nothing here forecloses that.
 *
 * ⚠ Only flips rows that are still encashable, and only earned leave, so a
 * business that has already turned it on deliberately is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')
            ->where('code', 'earned')
            ->where('can_encash', true)
            ->update(['can_encash' => false]);
    }

    public function down(): void
    {
        // Irreversible by intent: re-enabling encashment is a policy decision
        // made per business in the settings, not a blanket rollback.
    }
};
