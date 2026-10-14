<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule #16 (approved 14 Oct 2026): a salary revision starts only on a payroll
 * cycle start (the 24th). A mid-cycle date is refused and the next 24th is
 * offered; when the proposer accepts it, the date they first asked for is kept
 * here beside the effective one, so the history shows both and nothing was
 * silently rounded. Null means the first date asked for was already a 24th.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_revisions', function (Blueprint $table) {
            $table->date('requested_effective_from')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('salary_revisions', function (Blueprint $table) {
            $table->dropColumn('requested_effective_from');
        });
    }
};
