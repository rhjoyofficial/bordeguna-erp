<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax held back from a supplier payment (finance decision C4, 29 Sep 2026).
 *
 * A supplier has a TYPE (goods, service, contractor...). The owner keeps dated
 * TDS and VDS rates per type. When a supplier is paid, the rate chosen on the
 * payment holds part of it back for NBR:
 *
 *   Dr Accounts Payable (the whole bill settled)
 *   Cr bank or till     (what actually left)
 *   Cr 2180 TDS Payable / 2185 VDS Payable (what is owed to NBR)
 *
 * ⚠ `supplier_payments.amount` stays the amount SETTLED on the bills, so every
 * payable reader is unchanged; only the money side is net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('supplier_type', 32)->nullable()->after('name');
        });

        Schema::create('withholding_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // tds | vds
            $table->string('supplier_type', 32)->nullable(); // null = any type
            $table->string('name');
            $table->decimal('rate', 7, 4); // percent
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'kind', 'effective_from']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('tds_rate_id')->nullable()->after('amount')->constrained('withholding_rates')->restrictOnDelete();
            $table->decimal('tds_amount', 15, 4)->default(0)->after('tds_rate_id');
            $table->foreignId('vds_rate_id')->nullable()->after('tds_amount')->constrained('withholding_rates')->restrictOnDelete();
            $table->decimal('vds_amount', 15, 4)->default(0)->after('vds_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tds_rate_id');
            $table->dropConstrainedForeignId('vds_rate_id');
            $table->dropColumn(['tds_amount', 'vds_amount']);
        });
        Schema::dropIfExists('withholding_rates');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('supplier_type');
        });
    }
};
