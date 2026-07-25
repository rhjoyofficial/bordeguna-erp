<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The attendance rule set as versioned, effective-dated data (POL-12).
     *
     * POL-12 answered "future effective date only" — a policy change must never
     * alter what a closed period already calculated. Storing the rules as an
     * effective-dated row (the same P1 pattern schedules use) means a day is
     * always judged by the policy in force on that day, and a revision is a new
     * row rather than an edit.
     *
     * `config` holds the grace windows, occurrence ladders and half-day rules,
     * because POL-03's real policy is tiered and POL-05 explicitly answered
     * "Configurable" — neither can be a constant in code.
     */
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->json('config');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'effective_from']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            // POL-08 ticked only "varies by shift", so the treatment itself
            // belongs on the shift. Existing shifts already carry break_minutes,
            // so deducting them is the behaviour-preserving default.
            $table->string('break_policy')->default('fixed_deduction')->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('break_policy');
        });

        Schema::dropIfExists('attendance_policies');
    }
};
