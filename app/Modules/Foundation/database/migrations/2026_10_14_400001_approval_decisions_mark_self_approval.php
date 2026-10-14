<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule #22 (approved 14 Oct 2026): self-approval on HR money (expense claims,
 * loans, salary raises, overtime, payroll runs) stays ALLOWED, as Joy decided
 * on 10 Sep, but each one now carries a required reason (the decision's note)
 * and this visible mark, so the approval history shows it plainly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->boolean('self_approved')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropColumn('self_approved');
        });
    }
};
