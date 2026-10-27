<?php

use App\Modules\Hrm\Actions\RecountEarnedLeaveOnPayrollCycle;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * ⭐ 27 Oct 2026 (Joy): "reset full recount from aug 24 to sep 23, sep 24
     * to oct 23 based on that add the amount". The calendar decisions for
     * 2026 months 8 and 9 are superseded and their grants reversed, and month
     * 9 is decided again on the cycle 24 Aug - 23 Sep. The work and its
     * reasoning live in `RecountEarnedLeaveOnPayrollCycle`.
     *
     * Safe to run more than once, and a no-op on a database with no calendar
     * decisions (a fresh install, the test suite).
     */
    public function up(): void
    {
        app(RecountEarnedLeaveOnPayrollCycle::class)->handle();
    }

    /**
     * ⚠ Nothing to undo by deleting: the ledger is append-only and the
     * superseded rows are the record. A reversal of the recount would be a
     * new set of entries, decided by a person, not a rollback.
     */
    public function down(): void {}
};
