<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer or supplier picked straight onto a voucher (finance decision C3,
 * option c, 29 Sep 2026).
 *
 * A Receive voucher line on a customer creates a REAL customer receipt,
 * allocated to that customer's open invoices oldest first; a Payment voucher
 * line on a supplier creates a real supplier payment against open bills. The
 * receipt or payment is the subledger document the statement, the ageing and
 * the reconciliation already read; its journal line is the voucher's own.
 *
 * `voucher_id` names the voucher that owns the document. Reversing the voucher
 * removes what it created, in the same transaction as the reversal journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('sales_order_id')->constrained('vouchers')->nullOnDelete();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('money_account_id')->constrained('vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
        });
    }
};
