<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026, FINAL-PLAN 3.4): an approved payroll
 * adjustment and a staff loan paid out before its first instalment can be
 * CANCELLED. Both rows are kept and marked; nothing is deleted.
 *
 *   payroll_adjustments   cancelled_at / by / reason, and the opposite line a
 *                         cancel writes into the next open month when the
 *                         original month is already closed for inputs
 *   loans                 cancelled_at / by / reason, and the journal that
 *                         reversed the payout (null when accounting was off)
 *
 * Rows cancelled before this existed: none (there was no way to cancel), so
 * nothing is back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('cancel_offset_id')->nullable();

            $table->foreign('cancelled_by', 'payroll_adj_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancel_offset_id', 'payroll_adj_cancel_offset_fk')->references('id')->on('payroll_adjustments')->restrictOnDelete();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('cancel_journal_id')->nullable();

            $table->foreign('cancelled_by', 'loans_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancel_journal_id', 'loans_cancel_journal_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign('loans_cancelled_by_fk');
            $table->dropForeign('loans_cancel_journal_fk');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason', 'cancel_journal_id']);
        });

        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropForeign('payroll_adj_cancelled_by_fk');
            $table->dropForeign('payroll_adj_cancel_offset_fk');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason', 'cancel_offset_id']);
        });
    }
};
