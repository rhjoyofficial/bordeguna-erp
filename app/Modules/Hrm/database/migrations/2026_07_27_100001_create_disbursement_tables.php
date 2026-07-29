<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * How an employee is actually paid (SAL-08, whose last ticked option is
         * "employee অনুযায়ী ভিন্ন" — it varies per employee).
         *
         * Until now the employee record held bank details *and* MFS details and
         * no statement of which one is used, so the payroll validator could only
         * ask the blunt question "are either of these filled in". With the method
         * recorded, it can ask the right one, and a disbursement can group cash
         * apart from bank apart from MFS — which is the "cash এবং bank আলাদা"
         * half of DIS-01.
         */
        Schema::table('employees', function (Blueprint $table) {
            $table->string('salary_payment_method')->nullable()->after('mfs_account_number');
        });

        /**
         * DIS-04's email delivery, recorded on the payslip it belongs to.
         *
         * Three columns rather than a delivery table: an email either went or it
         * did not, and re-sending replaces the last attempt rather than adding to
         * a history anyone reads. The error is kept because a bounced payslip is
         * something HR must chase, not something to discover by its absence.
         */
        Schema::table('payslips', function (Blueprint $table) {
            $table->dateTime('emailed_at')->nullable();
            $table->string('emailed_to')->nullable();
            $table->string('email_error')->nullable();
        });

        /**
         * A group of payslips paid together by one method (DIS-01).
         *
         * DIS-01 ticked every option, and the batch is what reconciles them: a
         * whole run marked paid is one batch per method, employee-wise
         * confirmation is the payments inside it, "cash and bank separate" falls
         * out of the method being on the batch rather than mixed within one, and
         * the bank advice upload/confirmation is an attachment plus a stated
         * reference on the batch itself.
         *
         * The batch holds no totals. What it collected is the sum of its
         * payments, for the same reason a loan balance is the sum of its ledger:
         * a stored total is a second version of the truth waiting to disagree.
         */
        Schema::create('disbursement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->string('method'); // SalaryPaymentMethod
            $table->string('note')->nullable();
            // DIS-01's "bank advice upload/confirmation": the reference the bank
            // gave back, alongside the advice file held by the Attachment engine.
            $table->string('advice_reference')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['payroll_run_id', 'method']);
        });

        /**
         * Which payslips a batch covers.
         *
         * Snapshotted at creation rather than derived from the employees' current
         * payment methods. An employee whose method changes next week must not
         * silently drop out of a bank advice that was already sent, and "whole
         * batch paid" has to mean a fixed list of people or it means nothing.
         */
        Schema::create('disbursement_batch_payslip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();

            // ⚠ Explicit name: generated is 66 chars, MySQL's limit is 64.
            $table->unique(['disbursement_batch_id', 'payslip_id'], 'disb_batch_payslip_batch_payslip_uniq');
            // A payslip belongs to at most one batch, so a second batch cannot
            // quietly pay the same person twice.
            $table->unique('payslip_id');
        });

        /**
         * Every payment event against a payslip, append-only.
         *
         * DIS-02 allows a payment to fail, to land in part, and to be retried, so
         * a single `paid` boolean cannot carry it. These are events instead:
         *
         *  - **paid** — money moved, `amount` is what moved.
         *  - **failed** — an attempt that delivered nothing, carrying the reason.
         *    It moves no money but it is on record, which is what makes DIS-02's
         *    "unpaid status and retry" a thing HR can see rather than infer.
         *  - **reversed** — undoes a specific earlier payment (a returned
         *    transfer), pointing at the row it reverses.
         *
         * What has been paid is the sum of paid minus reversed, so the payslip's
         * payment status is derived and never stored, exactly like the loan
         * balance and the leave balance before it.
         */
        Schema::create('payslip_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disbursement_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type'); // PaymentEventType
            $table->string('method'); // SalaryPaymentMethod
            // Always positive — what the event was worth. Whether it added to or
            // took away from the paid total is the event type's job, so a stray
            // sign cannot silently turn a reversal into a second payment.
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('payslip_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payslip_id', 'event_type']);
            $table->index(['disbursement_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_payments');
        Schema::dropIfExists('disbursement_batch_payslip');
        Schema::dropIfExists('disbursement_batches');

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['emailed_at', 'emailed_to', 'email_error']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('salary_payment_method');
        });
    }
};
