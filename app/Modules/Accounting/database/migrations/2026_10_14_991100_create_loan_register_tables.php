<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ THE LOAN REGISTER (loans plan v2, phase 2, 14 Oct 2026): the CONTRACTUAL
 * sub-ledger beside the general ledger, never a second ledger.
 *
 *   loan_contracts        the agreement: who, which way, how much, on what terms
 *   loan_terms_versions   each set of terms the loan has had (an amendment adds
 *                         one; nothing older is rewritten)
 *   loan_schedule_rows    what falls due when, per terms version (old versions
 *                         stay as history)
 *   loan_entries          every movement the register knows: money in and out,
 *                         interest accrued, written off, waived, and manual
 *                         vouchers that name the loan. Each points at the ONE
 *                         journal PostJournal wrote for it.
 *   loan_allocations      which schedule row each movement settled
 *   loan_amendments       a requested change of terms, approved by the Owner
 *   loan_write_offs       a principal write-off or an interest waiver, approved
 *                         by the Owner with reason and evidence
 *   loan_tds_rules        the effective-dated withholding rule on interest paid
 *                         (default seeded OFF until the accountant confirms it)
 *
 * ⚠ The register never posts by itself: every journal comes from a user action
 * and goes through PostJournal, the only way into the ledger.
 *
 * ⚠ journal_lines.loan_contract_id is a sub-ledger DIMENSION like party_id: a
 * line on a loan head says which loan it belongs to, so the register and the
 * ledger can be totalled against each other loan by loan.
 *
 * ⚠ MySQL: every identifier stays under 64 characters; money is whole paisa in
 * BIGINT; nothing here has a JSON default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('loan_number', 20);
            $table->string('direction', 8);
            $table->foreignId('counterparty_id')->constrained('counterparties')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('BDT');
            $table->bigInteger('principal_minor');
            $table->date('start_date');
            $table->date('maturity_date');
            $table->decimal('annual_rate', 8, 4)->default(0);
            $table->text('interest_free_reason')->nullable();
            $table->string('day_count', 12)->default('actual_365');
            $table->string('repayment_type', 20);
            $table->string('frequency', 12);
            $table->unsignedSmallInteger('grace_months')->default(0);
            $table->string('waterfall', 16)->default('interest_first');
            $table->text('collateral')->nullable();
            $table->text('purpose')->nullable();
            $table->foreignId('principal_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('current_portion_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('interest_accrual_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('interest_pl_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('tds_certificate_ref', 120)->nullable();
            $table->decimal('tds_certificate_rate', 8, 4)->nullable();
            $table->unsignedSmallInteger('terms_version')->default(1);
            $table->text('cancel_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('settled_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'loan_number']);
            $table->index(['business_id', 'status']);
            $table->index(['counterparty_id', 'status']);
        });

        Schema::create('loan_terms_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->date('effective_from');
            $table->json('terms');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_contract_id', 'version']);
        });

        Schema::create('loan_schedule_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->bigInteger('principal_due_minor')->default(0);
            $table->bigInteger('interest_due_minor')->default(0);
            $table->timestamps();

            $table->index(['loan_contract_id', 'version', 'due_date']);
            $table->index(['business_id', 'due_date']);
        });

        Schema::create('loan_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_contract_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('from_version');
            $table->unsignedSmallInteger('to_version')->nullable();
            $table->date('effective_from');
            $table->json('terms');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['loan_contract_id', 'status']);
        });

        Schema::create('loan_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_contract_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->bigInteger('amount_minor')->default(0);
            $table->bigInteger('forgo_minor')->default(0);
            $table->date('effective_date');
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['loan_contract_id', 'status']);
        });

        Schema::create('loan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_contract_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->date('entry_date');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            // Signed changes the entry makes, each in paisa (see LoanEntry).
            $table->bigInteger('principal_minor')->default(0);
            $table->bigInteger('accrued_interest_minor')->default(0);
            $table->bigInteger('interest_pl_minor')->default(0);
            $table->bigInteger('interest_settled_minor')->default(0);
            $table->bigInteger('tds_minor')->default(0);
            $table->decimal('tds_rate', 8, 4)->nullable();
            $table->bigInteger('cash_minor')->default(0);
            $table->string('accrual_period', 7)->nullable();
            $table->unsignedSmallInteger('accrual_version')->nullable();
            // ⚠ Unique while live: one accrual per loan, month and terms
            // version. Cleared on reversal so the month can be accrued again.
            $table->string('accrual_key', 80)->nullable()->unique();
            // ⚠ A form sent twice (a double click, a retry) is refused here.
            $table->string('submission_key', 64)->nullable()->unique();
            $table->foreignId('loan_write_off_id')->nullable()->constrained('loan_write_offs')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            // ⚠ One journal links to one loan once (a voucher links once).
            $table->unique(['loan_contract_id', 'journal_id']);
            $table->index(['loan_contract_id', 'entry_date']);
            $table->index(['business_id', 'kind', 'entry_date']);
        });

        Schema::create('loan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_schedule_row_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('principal_minor')->default(0);
            $table->bigInteger('interest_minor')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('loan_tds_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('applies_to', 40);
            $table->json('payee_kinds');
            $table->decimal('rate', 8, 4);
            $table->text('legal_basis');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('confirmed_by', 120)->nullable();
            $table->date('confirmed_on')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'applies_to', 'effective_from']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('loan_contract_id')->nullable()->after('party_id')->constrained('loan_contracts')->restrictOnDelete();
        });

        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->foreignId('loan_contract_id')->nullable()->after('party_id')->constrained('loan_contracts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_contract_id');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_contract_id');
        });

        Schema::dropIfExists('loan_tds_rules');
        Schema::dropIfExists('loan_allocations');
        Schema::dropIfExists('loan_entries');
        Schema::dropIfExists('loan_write_offs');
        Schema::dropIfExists('loan_amendments');
        Schema::dropIfExists('loan_schedule_rows');
        Schema::dropIfExists('loan_terms_versions');
        Schema::dropIfExists('loan_contracts');
    }
};
