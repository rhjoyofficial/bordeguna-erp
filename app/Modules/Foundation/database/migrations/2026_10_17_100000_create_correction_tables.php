<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of edit, delete and correct (PHASE-0-CONTRACT v2, 17 Oct 2026).
 *
 *   correction_requests      a correction waiting for a second person or the Owner
 *   correction_links         the chain: original -> reversal -> successor (append-only)
 *   closed_period_exceptions one Owner exception for ONE document in a closed month
 *
 * ⚠⚠ Nothing here is ever deleted by the application. A used exception, a link
 * and a decided request are the evidence the monthly Corrections report reads,
 * and the owner ruled "no permanent purge, ever" (FINAL-PLAN section 9).
 *
 * ⚠ The database is the last word on the two races that matter:
 *   - `correction_links.live_key` is UNIQUE: one reversal per document, whatever
 *     the application checks did or did not see;
 *   - `(business_id, idempotency_key)` is UNIQUE on links and requests: a retry
 *     of the same click can never make a second correction.
 * `correction_requests.pending_key` does the same for "one waiting request per
 * document" (set while pending, cleared when decided; MySQL allows many NULLs).
 *
 * Index and foreign key names are short and explicit: MySQL stops at 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('document_type', 150);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number', 40);
            $table->string('action', 30);
            $table->json('payload');
            $table->json('dates');
            $table->foreignId('reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->text('reason_text');
            $table->string('document_fingerprint', 64);
            $table->bigInteger('amount_basis_minor');
            $table->bigInteger('original_minor')->default(0);
            $table->bigInteger('successor_minor')->nullable();
            $table->string('approval_route', 30);
            $table->string('workflow_key', 40);
            $table->unsignedBigInteger('owner_authorised_by')->nullable();
            $table->unsignedBigInteger('requested_by');
            // The original's maker and last editor: never the second person.
            $table->json('excluded_approvers')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('duplicate_ack')->nullable();
            $table->json('execution_summary')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedBigInteger('executed_link_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('pending_key', 191)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_authorised_by', 'corr_req_owner_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requested_by', 'corr_req_requester_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['business_id', 'idempotency_key'], 'corr_req_idem_unique');
            $table->unique('pending_key', 'corr_req_pending_unique');
            $table->index(['business_id', 'created_at'], 'corr_req_month_index');
            $table->index(['document_type', 'document_id'], 'corr_req_document_index');
        });

        Schema::create('closed_period_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('closed_through_snapshot');
            $table->string('original_type', 150);
            $table->unsignedBigInteger('original_id');
            $table->string('original_number', 40);
            $table->string('action', 30);
            $table->string('successor_type', 150)->nullable();
            $table->unsignedBigInteger('successor_id')->nullable();
            $table->json('dates');
            $table->unsignedTinyInteger('max_journals');
            $table->unsignedTinyInteger('journals_posted')->default(0);
            $table->string('secret_hash', 64);
            $table->unsignedBigInteger('granted_by');
            $table->unsignedBigInteger('executed_by');
            $table->foreignId('reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->text('reason_text');
            $table->unsignedBigInteger('correction_request_id')->nullable();
            $table->unsignedBigInteger('correction_link_id')->nullable();
            $table->string('status', 20)->default('granted');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('granted_by', 'cpe_granted_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('executed_by', 'cpe_executed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('correction_request_id', 'cpe_request_fk')->references('id')->on('correction_requests')->restrictOnDelete();
            $table->index(['original_type', 'original_id'], 'cpe_original_index');
            $table->index(['business_id', 'created_at'], 'cpe_month_index');
        });

        Schema::create('correction_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('original_type', 150);
            $table->unsignedBigInteger('original_id');
            $table->string('original_number', 40);
            $table->date('original_date');
            $table->unsignedBigInteger('original_journal_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();
            $table->string('successor_type', 150)->nullable();
            $table->unsignedBigInteger('successor_id')->nullable();
            $table->string('successor_number', 40)->nullable();
            $table->date('successor_date')->nullable();
            $table->unsignedBigInteger('successor_journal_id')->nullable();
            $table->foreignId('reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->text('reason_text');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('correction_request_id')->nullable();
            $table->date('date_used');
            $table->string('date_rule', 30);
            $table->boolean('closed_period_exception')->default(false);
            $table->unsignedBigInteger('period_exception_id')->nullable();
            $table->bigInteger('amount_basis_minor');
            $table->bigInteger('original_minor');
            $table->bigInteger('successor_minor')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('live_key', 191)->nullable();
            // ⚠ The moment of the click, never backdated. No updated_at: the
            // row is never updated (the model refuses).
            $table->timestamp('created_at')->nullable();

            $table->foreign('original_journal_id', 'corr_link_orig_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'corr_link_rev_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->foreign('successor_journal_id', 'corr_link_succ_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->foreign('actor_id', 'corr_link_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'corr_link_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('correction_request_id', 'corr_link_request_fk')->references('id')->on('correction_requests')->restrictOnDelete();
            $table->foreign('period_exception_id', 'corr_link_exception_fk')->references('id')->on('closed_period_exceptions')->restrictOnDelete();
            $table->unique(['business_id', 'idempotency_key'], 'corr_link_idem_unique');
            $table->unique('live_key', 'corr_link_live_unique');
            $table->index(['business_id', 'created_at'], 'corr_link_month_index');
            $table->index(['original_type', 'original_id'], 'corr_link_original_index');
            $table->index(['successor_type', 'successor_id'], 'corr_link_successor_index');
        });

        Schema::table('correction_requests', function (Blueprint $table) {
            $table->foreign('executed_link_id', 'corr_req_link_fk')->references('id')->on('correction_links')->restrictOnDelete();
        });

        Schema::table('closed_period_exceptions', function (Blueprint $table) {
            $table->foreign('correction_link_id', 'cpe_link_fk')->references('id')->on('correction_links')->restrictOnDelete();
        });

        Schema::create('duplicate_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('rule_key', 60);
            $table->string('subject_type', 150);
            $table->unsignedBigInteger('subject_id');
            $table->string('payload_hash', 64);
            $table->json('candidates');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id', 'dup_ack_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['business_id', 'created_at'], 'dup_ack_month_index');
            $table->index(['subject_type', 'subject_id'], 'dup_ack_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('closed_period_exceptions', fn (Blueprint $table) => $table->dropForeign('cpe_link_fk'));
        Schema::table('correction_requests', fn (Blueprint $table) => $table->dropForeign('corr_req_link_fk'));
        Schema::dropIfExists('duplicate_acknowledgements');
        Schema::dropIfExists('correction_links');
        Schema::dropIfExists('closed_period_exceptions');
        Schema::dropIfExists('correction_requests');
    }
};
