<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two leads that turn out to be one person.
 *
 * ⚠⚠ THE DUPLICATE IS LINKED AND KEPT, NEVER ABSORBED. The obvious "merge"
 * moves the duplicate's activities, tasks and interests onto the survivor and
 * deletes the row - which is a retrospective edit of history wearing a helpful
 * name, and exactly what this codebase refuses everywhere else.
 *
 * ⚠ No new LeadStatus case: "is a duplicate" is `duplicate_of_lead_id !== null`.
 * An eighth enum case would shatter every exhaustive match over it to express
 * something a nullable foreign key already says.
 *
 * CRM-PHASE-B-ARCHITECTURE.md, C-CRMB-31..37.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // ⚠ restrictOnDelete: the survivor must not disappear and leave a
            // duplicate pointing at nothing.
            $table->foreignId('duplicate_of_lead_id')->nullable()->after('converted_at')
                ->constrained('leads')->restrictOnDelete();
            $table->timestamp('merged_at')->nullable()->after('duplicate_of_lead_id');
            $table->foreignId('merged_by')->nullable()->after('merged_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['business_id', 'duplicate_of_lead_id'], 'leads_duplicate_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_duplicate_idx');
            $table->dropForeign(['duplicate_of_lead_id']);
            $table->dropForeign(['merged_by']);
            $table->dropColumn(['duplicate_of_lead_id', 'merged_at', 'merged_by']);
        });
    }
};
