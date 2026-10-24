<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an approved overtime, bonus
 * award or salary revision can be cancelled, with a reason, while the payroll
 * month it belongs to still takes inputs (CancelPayrollInput). The row stays,
 * marked with who cancelled it, when and why; payroll skips it.
 *
 * ⛔ A locked, approved or paid payroll is never touched (August 2026 is locked
 * at net 230,805): a month that no longer takes inputs refuses the cancel.
 *
 * ⚠ One overtime per employee-day and one award per employee per bonus stay
 * unique for LIVE rows only, so a cancelled one can be entered again right:
 * `live` is 1 on a live row and NULL once cancelled, and a unique index
 * treats NULLs as distinct (MySQL and SQLite alike). The new index is made
 * before the old one goes, because the foreign key needs one.
 */
return new class extends Migration
{
    private const TABLES = ['overtime_assignments', 'bonus_awards', 'salary_revisions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->text('cancel_reason')->nullable();

                $table->foreign('cancelled_by', "{$name}_cancelled_by_fk")->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::table('overtime_assignments', function (Blueprint $table) {
            $table->boolean('live')->nullable()->default(true);
            $table->unique(['employee_id', 'work_date', 'live'], 'overtime_assignments_live_day_unique');
        });
        Schema::table('overtime_assignments', fn (Blueprint $table) => $table->dropUnique(['employee_id', 'work_date']));

        Schema::table('bonus_awards', function (Blueprint $table) {
            $table->boolean('live')->nullable()->default(true);
            $table->unique(['bonus_event_id', 'employee_id', 'live'], 'bonus_awards_live_award_unique');
        });
        Schema::table('bonus_awards', fn (Blueprint $table) => $table->dropUnique(['bonus_event_id', 'employee_id']));
    }

    public function down(): void
    {
        Schema::table('overtime_assignments', fn (Blueprint $table) => $table->unique(['employee_id', 'work_date']));
        Schema::table('overtime_assignments', function (Blueprint $table) {
            $table->dropUnique('overtime_assignments_live_day_unique');
            $table->dropColumn('live');
        });
        Schema::table('bonus_awards', fn (Blueprint $table) => $table->unique(['bonus_event_id', 'employee_id']));
        Schema::table('bonus_awards', function (Blueprint $table) {
            $table->dropUnique('bonus_awards_live_award_unique');
            $table->dropColumn('live');
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_cancelled_by_fk");
                $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
            });
        }
    }
};
