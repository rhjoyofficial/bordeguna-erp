<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3, HRM (24 Oct 2026; FINAL-PLAN 3.4).
 *
 * - leave_requests / movements: Cancel after approval. The row stays, marked
 *   Cancelled with who, when and why; the leave days go back through a new
 *   ledger row (the ledger is append-only, nothing is rewritten).
 * - employees: `status_before_separation` (so Undo separation knows what to
 *   go back to), and the Recycle bin columns for an unused draft.
 * - show_cause_notices: Withdraw with a date and a reason. `notified_at` says
 *   whether the letter reached the employee; only then do they see the
 *   withdrawal. Back-filled from the employee having a login when it was issued
 *   (the notification went to every employee with one).
 * - hr_correction_requests: an HR cancellation or undo that waits for a
 *   second person (cancel after that month's payroll is approved, undo a
 *   separation). Nothing changes while it waits.
 * - The `hr_correction_request` workflow per business: one stage, the Owner,
 *   a Manager, or anybody holding HR's permissions; the requester is refused
 *   by the model itself (Joy, 19 Oct 2026: only the requester).
 *
 * ⚠ Data touched on a live database: nullable columns only (existing rows
 * stay as they are), the notified_at back-fill, and one workflow + stage per
 * business, inserted only where the key is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_requests', 'movements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('cancelled_at')->nullable();
                $blueprint->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->text('cancel_reason')->nullable();
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->string('status_before_separation')->nullable();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();
        });

        Schema::table('show_cause_notices', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
            $table->date('withdrawn_on')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdraw_reason')->nullable();
        });

        foreach (DB::table('show_cause_notices')->get(['id', 'employee_id', 'issued_at']) as $notice) {
            $hadLogin = DB::table('employees')->where('id', $notice->employee_id)->whereNotNull('user_id')->exists();

            if ($hadLogin) {
                DB::table('show_cause_notices')->where('id', $notice->id)->update(['notified_at' => $notice->issued_at]);
            }
        }

        Schema::create('hr_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // cancel_leave | cancel_movement | undo_separation
            $table->string('action', 30);
            $table->morphs('subject');
            $table->string('subject_label');
            $table->foreignId('reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->text('reason_text');
            // Why it waits, one sentence, for the inbox card.
            $table->string('why');
            // The record's state when it was asked; a change means "look again".
            $table->string('fingerprint', 64);
            // pending | executed | rejected | failed
            $table->string('status', 20)->default('pending');
            // Set while pending, so one record has at most one waiting request.
            $table->string('pending_key')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'pending_key']);
            $table->index(['business_id', 'status']);
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $workflowId = DB::table('approval_workflows')->where('business_id', $businessId)->where('key', 'hr_correction_request')->value('id')
                ?? DB::table('approval_workflows')->insertGetId([
                    'business_id' => $businessId,
                    'key' => 'hr_correction_request',
                    'name' => 'HR cancellation or undo (second person)',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (! DB::table('approval_workflow_stages')->where('approval_workflow_id', $workflowId)->exists()) {
                DB::table('approval_workflow_stages')->insert([
                    'approval_workflow_id' => $workflowId,
                    'sequence' => 1,
                    'name' => 'Second person',
                    'allowed_roles' => json_encode(['Owner', 'Manager']),
                    'allowed_permissions' => json_encode(['hrm.leave.manage', 'hrm.employees.manage']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $workflowIds = DB::table('approval_workflows')->where('key', 'hr_correction_request')->pluck('id');
        DB::table('approval_workflow_stages')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();

        Schema::dropIfExists('hr_correction_requests');

        Schema::table('show_cause_notices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['notified_at', 'withdrawn_on', 'withdrawn_at', 'withdraw_reason']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['status_before_separation', 'deleted_at', 'delete_reason']);
        });

        foreach (['leave_requests', 'movements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('cancelled_by');
                $blueprint->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }
    }
};
