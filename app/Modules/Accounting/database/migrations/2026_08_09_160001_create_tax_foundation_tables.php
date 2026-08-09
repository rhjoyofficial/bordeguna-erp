<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT-ready foundation. VAT-READY-ARCHITECTURE.md, C-VAT-1..12.
 *
 * ⚠⚠ THIS MIGRATION TURNS NOTHING ON. Every figure it adds defaults to 0 or
 * false, no posting path reads any of it, and `vat_enabled` is false everywhere.
 * Joy's decision on 9 August: build the model and the switch now, leave the
 * calculation dormant until the regime is confirmed.
 *
 * ⚠ Why now rather than when VAT arrives: these columns land on tables that will
 * by then hold real purchase and sales history, and the chart of accounts is
 * GLOBAL and shared by four businesses. Adding them to an empty system costs
 * nothing; adding them to a full one is a migration nobody wants to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── C-VAT-1 / C-VAT-5: the business settings ────────────────────────
        Schema::table('businesses', function (Blueprint $table) {
            // ⚠⚠ C-VAT-1, ANSWERED BY JOY 9 AUGUST: Bor De Guna's customer-facing
            // price IS the final price the customer pays. VAT is never added on
            // top of it - when the engine arrives it will be extracted from
            // within. Default true because that is the answer for this
            // installation; it is a column and not a constant because it is a
            // commercial decision that another business may make differently.
            $table->boolean('prices_include_tax')->default(true)->after('currency');

            // ⚠⚠ C-VAT-9: the toggle exists and STAYS OFF. The UI keeps it
            // disabled until the engine is built - a button that claims to work
            // and does not is worse than no button.
            $table->boolean('vat_enabled')->default(false)->after('prices_include_tax');

            // ⚠⚠ C-VAT-5: VAT starts FROM A DATE, never retrospectively. The
            // ledger is immutable and closed periods are locked, so switching
            // VAT on must not change a single invoice already posted.
            $table->date('vat_effective_from')->nullable()->after('vat_enabled');

            // ⚠ C-VAT-2 DELIBERATELY OPEN. Standard 15% VAT and 4% turnover tax
            // are different engines; Joy has not confirmed which regime applies.
            // Nullable with no default, so "undecided" is a state the database
            // can actually represent rather than a guess dressed as a default.
            $table->string('vat_regime')->nullable()->after('vat_effective_from');
        });

        // ── C-VAT-11: what KIND of thing this is, for tax purposes ──────────
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        // ── C-VAT-3: rates are effective-dated and never edited in place ────
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_class_id')->constrained()->cascadeOnDelete();

            // 8,4 holds 15.0000 and also 7.5000 or 0.0000. A rate is a
            // percentage, not money, so it does not use the Money scale.
            $table->decimal('rate', 8, 4);

            // ⚠⚠ C-VAT-3. A rate is NEVER edited in place - a new row with a
            // later date supersedes it, exactly as ProductPrice and
            // AccountMapping work. Rates change by government order, and an
            // invoice from last year must still be explicable by the rate that
            // applied when it was raised.
            $table->date('effective_from');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⚠ Short name, deliberately. MySQL caps an identifier at 64
            // characters and Laravel's generated name for three columns on this
            // table would be close enough to it to be worth not risking.
            $table->index(['business_id', 'tax_class_id', 'effective_from'], 'tax_rates_lookup_idx');
        });

        // ── C-VAT-11: a product's tax class, optional ───────────────────────
        Schema::table('products', function (Blueprint $table) {
            // ⚠ Nullable, and NOT a silent zero-rating. A product with no class
            // falls back to the business default rate when the engine is built;
            // it never means "charge nothing".
            $table->foreignId('tax_class_id')->nullable()->after('unit_of_measure_id')
                ->constrained()->nullOnDelete();
        });

        // ── The purchase side, so input VAT has somewhere to live ───────────
        //
        // ⚠⚠ THIS DOES NOT CHANGE WHAT `unit_cost` MEANS. The migration of
        // 2 August documented the standing assumption: unit_cost is the amount
        // paid per unit INCLUSIVE of taxes, and inventory is valued GROSS.
        // These columns record a tax component alongside it for the future; they
        // do not net anything out of stock valuation, and nothing reads them.
        // Whether inventory is revalued net is part of the engine, and is a
        // decision Joy has not been asked for yet. See C-VAT-13.
        foreach (['purchase_order_lines', 'supplier_bill_lines'] as $lines) {
            Schema::table($lines, function (Blueprint $table) {
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
            });
        }

        foreach (['purchase_orders', 'supplier_bills'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->decimal('tax_total', 15, 4)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_orders', 'supplier_bills'] as $documents) {
            Schema::table($documents, fn (Blueprint $table) => $table->dropColumn('tax_total'));
        }

        foreach (['purchase_order_lines', 'supplier_bill_lines'] as $lines) {
            Schema::table($lines, fn (Blueprint $table) => $table->dropColumn(['tax_rate', 'tax_amount']));
        }

        Schema::table('products', function (Blueprint $table) {
            // ⚠ The constraint before the column, or MySQL refuses the drop.
            $table->dropForeign(['tax_class_id']);
            $table->dropColumn('tax_class_id');
        });

        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_classes');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['prices_include_tax', 'vat_enabled', 'vat_effective_from', 'vat_regime']);
        });
    }
};
