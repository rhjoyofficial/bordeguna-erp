<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Party ledgers on vouchers, Tally style (Joy, 18 Oct 2026: "how do I give
 * them money or how do they give me?").
 *
 * Schema only: no existing row is read or changed.
 *
 * - `vouchers.party_*`: who the voucher is "Paid to / Received from", picked
 *   from the suppliers, customers and employees (the free-text
 *   `counterparty` stays for everyone else, and carries the printed name).
 * - `voucher_lines.bill_wise`: the line's bill-wise details as the person
 *   typed them (Against bill / Advance), a list of {ref, amount}. NULL means
 *   "oldest first", what every voucher before this did.
 * - `voucher_lines.tds_rate_id` / `vds_rate_id`: tax held back on a supplier
 *   payment line, through the rates the owner keeps (never a hard-coded rate).
 * - `voucher_id` on supplier refunds (SREF), supplier advance refunds (SARF),
 *   customer refunds and party adjustments: the voucher that made the
 *   document. Its journal line is the voucher's own; reversing the voucher
 *   undoes the document, as for receipts and payments since 29 Sep 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->nullableMorphs('party');
        });

        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->json('bill_wise')->nullable()->after('narration');
            $table->foreignId('tds_rate_id')->nullable()->after('bill_wise')->constrained('withholding_rates')->nullOnDelete();
            $table->foreignId('vds_rate_id')->nullable()->after('tds_rate_id')->constrained('withholding_rates')->nullOnDelete();
        });

        foreach (['supplier_refunds', 'supplier_advance_refunds', 'customer_refunds', 'party_adjustments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['supplier_refunds', 'supplier_advance_refunds', 'customer_refunds', 'party_adjustments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('voucher_id');
            });
        }

        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vds_rate_id');
            $table->dropConstrainedForeignId('tds_rate_id');
            $table->dropColumn('bill_wise');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropMorphs('party');
        });
    }
};
