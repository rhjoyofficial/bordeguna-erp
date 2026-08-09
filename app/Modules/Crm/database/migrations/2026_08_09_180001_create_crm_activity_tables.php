<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Phase B: what happened, and what is due.
 *
 * ⚠⚠ TWO TABLES ON PURPOSE. An activity is immutable history - a call that was
 * made, a note of what was said. A task is a mutable intention - rescheduled,
 * completed, cancelled. Forcing them into one table means either history becomes
 * editable, destroying the audit value that is the entire point, or a follow-up
 * can never be moved. See CRM-PHASE-B-ARCHITECTURE.md §2.
 *
 * ⚠ Both attach polymorphically to a LEAD or a CUSTOMER. A relationship does not
 * begin at conversion, so both need a history - and they stay separate models,
 * per Joy's instruction not to collapse them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ Polymorphic over Lead and Customer ONLY. The application
            // validates against a whitelist rather than accepting whatever a
            // request names (C-CRMB-4).
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('type', 20);
            // Null for a note, which has no direction. Calls and messages do.
            $table->string('direction', 10)->nullable();

            // ⚠⚠ SEPARATE FROM created_at, and this is the point of the column.
            // A call made this morning and typed up this evening HAPPENED this
            // morning. A report using created_at to answer "when did we last
            // contact them" is wrong, and wrong in a way nobody notices.
            $table->timestamp('occurred_at');

            $table->string('summary');
            $table->text('body')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⚠ Short explicit name. MySQL caps an identifier at 64 characters
            // and the generated name for four columns on a table with this
            // prefix would be uncomfortably close to it.
            $table->index(['business_id', 'subject_type', 'subject_id', 'occurred_at'], 'crm_act_subject_idx');
            $table->index(['business_id', 'occurred_at'], 'crm_act_when_idx');
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('title');
            $table->text('note')->nullable();
            $table->timestamp('due_at');

            // ⚠⚠ THE WORK QUEUE. Membership is checked through the business_user
            // PIVOT, not a business_id on users - that column does not exist,
            // and a scoped exists rule against `users` would match nothing and
            // silently reject every valid agent (C-CRMB-5).
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // open | done | cancelled. ⚠ A cancelled task is cancelled, never
            // deleted: "we decided not to call back" is information.
            $table->string('status', 12)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'assigned_to_user_id', 'status', 'due_at'], 'crm_task_queue_idx');
            $table->index(['business_id', 'subject_type', 'subject_id'], 'crm_task_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_activities');
    }
};
