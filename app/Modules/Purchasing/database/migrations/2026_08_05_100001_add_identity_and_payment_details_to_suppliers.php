<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Purchasing and Accounting need to know about a supplier, added before
     * onboarding rather than after (Joy, 2026-08-05).
     *
     * ⚠⚠ Added NOW on purpose. Every field here is something a person collects
     * while they are already on the phone to the supplier. Retrofitting a tax
     * number is not a migration, it is ringing every supplier back.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // ⚠⚠ THE PERMANENT IDENTIFIER, and the import's idempotency key.
            // The only other candidate was `name`, and names get corrected -
            // "Acme Traders" becomes "Acme Trading Co" - after which re-running
            // an import would create a SECOND supplier rather than update the
            // first. Same reasoning as SKU versus barcode.
            //
            // ⚠ Nullable at the database level and unique per business when
            // present, exactly like `customers.code`. MySQL permits many NULLs
            // in a unique index, so "mandatory in practice, optional in schema"
            // needs no partial index. The import requires it.
            $table->string('code')->nullable()->after('business_id');

            // ─── Tax identity ────────────────────────────────────────────────
            // ⚠ Bangladesh: BIN is the VAT registration, TIN the income-tax one.
            // A supplier's BIN is what a purchase document needs before input
            // VAT can be claimed, and C-SLS-3 (are we VAT registered?) is still
            // open with the accountant - so these are collected now regardless
            // of how that lands.
            //
            // ⚠⚠ NAMED COLUMNS, not a generic key-value store, and that IS the
            // extensible choice Joy asked for. Adding a third identifier later
            // is one additive migration touching nothing that exists. A JSON
            // blob or an EAV table would buy flexibility nobody has asked for
            // at the cost of validation, querying and readability today - and
            // this project has been bitten by speculative generality before.
            $table->string('bin')->nullable()->after('payment_terms');
            $table->string('tin')->nullable()->after('bin');

            // ─── How we actually pay them ────────────────────────────────────
            // ⚠ Operational facts, not accounting transactions. Supplier
            // payments are manual bank transfers, so the person making one needs
            // these - and until now they lived on paper while the payment
            // DOCUMENT lived in the ERP. That is one act with two sources of
            // truth.
            $table->string('bank_name')->nullable()->after('tin');
            $table->string('bank_branch')->nullable()->after('bank_name');
            // ⚠ Often NOT the trading name. Paying to the wrong account name is
            // how a transfer bounces a week later.
            $table->string('bank_account_name')->nullable()->after('bank_branch');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('mfs_number')->nullable()->after('bank_account_number');

            // ⚠ Deliberately free text. Preferred delivery time, packaging
            // requirements, who to ask for - real operational knowledge that
            // would otherwise live in one person's head, and that resists
            // structuring precisely because every supplier's is different.
            $table->text('notes')->nullable()->after('mfs_number');

            $table->unique(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->dropColumn([
                'code', 'bin', 'tin', 'bank_name', 'bank_branch',
                'bank_account_name', 'bank_account_number', 'mfs_number', 'notes',
            ]);
        });
    }
};
