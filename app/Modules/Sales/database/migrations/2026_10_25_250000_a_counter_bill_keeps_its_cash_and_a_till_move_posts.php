<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B12 (Joy's initial plan, 25 Oct 2026): counter multi-pay and shift cash.
 *
 *   - A counter bill remembers what the customer handed over in cash and the
 *     change given back (cash line only). Until now "Cash given" was shown on
 *     the screen and thrown away, so nobody could later say what was tendered.
 *   - A till movement on a cashier shift is now a real document: a money
 *     transfer (cash in from, or cash out to, another of our accounts) or an
 *     expense voucher (cash spent from the drawer on a chosen head). The row
 *     keeps a pointer to it, so the shift screen and the ledger agree and a
 *     movement is never counted twice. Old rows keep both pointers empty: they
 *     were notes only and still count as notes in "expected".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('cash_given', 14, 2)->nullable();
            $table->decimal('change_given', 14, 2)->nullable();
        });

        Schema::table('pos_cash_movements', function (Blueprint $table) {
            $table->string('kind', 20)->nullable(); // transfer | expense; null = an old note
            $table->foreignId('money_transfer_id')->nullable()->constrained('money_transfers')->nullOnDelete();
            $table->foreignId('expense_voucher_id')->nullable()->constrained('expense_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_transfer_id');
            $table->dropConstrainedForeignId('expense_voucher_id');
            $table->dropColumn('kind');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['cash_given', 'change_given']);
        });
    }
};
