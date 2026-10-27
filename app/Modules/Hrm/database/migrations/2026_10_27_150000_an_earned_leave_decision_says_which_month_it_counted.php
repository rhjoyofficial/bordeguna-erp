<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ⭐ 27 Oct 2026 (Joy): "reset full recount from aug 24 to sep 23, sep 24
     * to oct 23 based on that add the amount". An earned-leave decision now
     * says which month it counted, can be superseded without being deleted,
     * and points at the grant entry it put on the ledger.
     *
     *  - `basis`: 'calendar' (1st to last day, how 2026 months 8 and 9 were
     *    decided on live) or 'cycle' (the 24-23 payroll cycle, every month
     *    from now on).
     *  - `superseded_at` / `superseded_reason`: set when the recount replaces
     *    a calendar decision. The row stays as the record of what was decided
     *    and why it stopped counting.
     *  - `leave_ledger_entry_id`: the grant that still stands for this
     *    decision, so a re-decision knows exactly which entry to reverse.
     *
     * ⚠⚠ The unique key moves from (business, employee, year, month) to
     * (business, employee, year, month, basis), so a cycle decision can sit
     * next to the superseded calendar one. `basis` is NOT NULL on purpose:
     * MySQL lets any number of NULLs through a unique index, so a nullable
     * column such as `superseded_at` in the key would guard nothing.
     *
     * ⚠ The new index is added BEFORE the old one is dropped. On MySQL the old
     * one is the index the business_id foreign key leans on, and dropping it
     * first is refused.
     */
    public function up(): void
    {
        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->string('basis', 16)->default('cycle')->after('month');
            $table->unsignedBigInteger('leave_ledger_entry_id')->nullable()->after('serious_lates');
            $table->timestamp('superseded_at')->nullable()->after('leave_ledger_entry_id');
            $table->string('superseded_reason')->nullable()->after('superseded_at');
        });

        // Every row already on live was decided on the calendar month: the
        // cycle code never ran there before this release.
        DB::table('earned_leave_accruals')
            ->where(fn ($query) => $query
                ->where('year', '<', 2026)
                ->orWhere(fn ($query) => $query->where('year', 2026)->where('month', '<=', 9)))
            ->update(['basis' => 'calendar']);

        // A granted row's entry carries the old once-key, el_accrual:y:m:emp.
        DB::table('earned_leave_accruals')
            ->where('days_granted', '>', 0)
            ->orderBy('id')
            ->get(['id', 'business_id', 'employee_id', 'year', 'month'])
            ->each(fn ($row) => DB::table('earned_leave_accruals')->where('id', $row->id)->update([
                'leave_ledger_entry_id' => DB::table('leave_ledger_entries')
                    ->where('business_id', $row->business_id)
                    ->where('source_key', sprintf('el_accrual:%d:%d:%d', $row->year, $row->month, $row->employee_id))
                    ->value('id'),
            ]));

        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->unique(['business_id', 'employee_id', 'year', 'month', 'basis'], 'el_accruals_basis_once_idx');
            $table->index('leave_ledger_entry_id', 'el_accruals_grant_idx');
        });

        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->dropUnique('el_accruals_once_idx');
        });
    }

    /**
     * ⚠ Fails while a superseded calendar row and a cycle row share a month:
     * the old key cannot hold both, and neither row is ours to delete.
     */
    public function down(): void
    {
        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->unique(['business_id', 'employee_id', 'year', 'month'], 'el_accruals_once_idx');
        });

        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->dropUnique('el_accruals_basis_once_idx');
            $table->dropIndex('el_accruals_grant_idx');
        });

        Schema::table('earned_leave_accruals', function (Blueprint $table) {
            $table->dropColumn(['basis', 'leave_ledger_entry_id', 'superseded_at', 'superseded_reason']);
        });
    }
};
