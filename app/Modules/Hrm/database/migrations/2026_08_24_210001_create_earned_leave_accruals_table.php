<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One month's earned-leave decision for one employee (Joy, 24 Aug 2026:
         * "Per month 1 day will per add to each employee quota unless...").
         *
         * ⚠ A row exists whether the day was GRANTED or WITHHELD, and that is
         * the point. The ledger can only record days that were added, so a
         * withheld month would otherwise be invisible - an employee asking "why
         * did I not get my day in March" would be answered by re-deriving a
         * count from attendance months later, which is exactly the sort of
         * answer that stops being reproducible once a day is corrected.
         *
         * The counts are stored, not recomputed: the decision cites the numbers
         * it was made on, so a later correction changes future accruals without
         * silently rewriting the reason an old one was refused.
         *
         * Unique per employee and month, so a rerun of the command decides once.
         */
        Schema::create('earned_leave_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('days_granted', 6, 2)->default(0);
            // Null when the day was granted; the stated reason when it was not.
            $table->string('withheld_reason')->nullable();
            $table->unsignedInteger('ordinary_lates')->default(0);
            $table->unsignedInteger('serious_lates')->default(0);
            $table->timestamps();

            // ⚠ Explicit name: the generated one exceeds MySQL's 64 characters.
            $table->unique(['business_id', 'employee_id', 'year', 'month'], 'el_accruals_once_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earned_leave_accruals');
    }
};
