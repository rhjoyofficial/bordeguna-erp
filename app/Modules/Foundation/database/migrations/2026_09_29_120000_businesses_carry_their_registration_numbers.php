<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business's own registration numbers and who signs for it.
 *
 * Joy's trade licence (29 Sep 2026) names the proprietor, a TIN and a licence
 * number valid for one fiscal year, and the finance review asked for them on
 * the voucher and invoice header. `tin` existed only on customers and
 * suppliers; the business itself had nowhere to keep one.
 *
 * ⚠ Nullable, like every other identity field: BusinessIdentity prints what is
 * set and nothing else. The BIN is NOT here - it already lives in `vat_bin`,
 * owned by VAT settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('tin', 30)->nullable()->after('website');
            $table->string('trade_licence_no', 40)->nullable()->after('tin');
            $table->date('trade_licence_valid_until')->nullable()->after('trade_licence_no');
            $table->string('signatory_name', 120)->nullable()->after('trade_licence_valid_until');
            $table->string('signatory_title', 80)->nullable()->after('signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['tin', 'trade_licence_no', 'trade_licence_valid_until', 'signatory_name', 'signatory_title']);
        });
    }
};
