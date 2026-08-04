<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a product sells for, on a date, to whom, in what quantity.
     *
     * ⚠⚠ A DATED RESOLUTION TABLE, the same shape as `account_mappings` - the
     * pattern this codebase has now proven twice. Joy's constraint: "pricing must
     * also support customer-specific, wholesale, retail, promotional and
     * quantity-based pricing in future. Build the architecture so pricing is
     * configurable rather than a single fixed field."
     *
     * This shape satisfies all of them without building any of them:
     *
     *   - A promotion is a row with an `effective_to`.
     *   - A quantity break is a row with a `min_quantity`.
     *   - A wholesale rate is a row against a `price_group`.
     *   - A negotiated rate is a row against a `customer_id`.
     *
     * ⚠ Phase 1 WRITES only default rows (no customer, no group, min 0) and
     * builds only the resolver. Screens for the rest arrive when somebody
     * actually needs a festival promotion. Building five pricing strategies
     * before the first sale is how a pricing engine becomes something people
     * work around.
     *
     * ⚠⚠ THE DEFAULT PRICE IS A ROW HERE, NOT A COLUMN ON `products`. The review
     * said "falling back to the product's default", and a column would have been
     * the obvious reading - but a default selling price CHANGES, and a column
     * silently overwrites the old one. Then an invoice raised in July cannot be
     * explained in September, because the rule that produced it no longer exists
     * anywhere. One table, one resolution path, and full history of every price
     * this business ever asked for.
     *
     * ⚠ None of this changes what an invoice shows. The resolved price is COPIED
     * onto the invoice line when the sale is made, exactly as `unit_cost` is
     * copied onto every stock movement. An invoice must always show what was
     * charged, not what the rule says today.
     */
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // ⚠ The three specificity tiers. Both null and `min_quantity` zero
            // is the default price; nothing else is special-cased.
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('price_group')->nullable();
            $table->decimal('min_quantity', 15, 4)->default(0);

            // ⚠ Tax-exclusive, with tax at zero, per C-SLS-3 and limitation L1.
            // Joy is holding the VAT decision until the accountant answers. If
            // that answer turns out to be "prices were VAT-inclusive all along",
            // every price stored before that day means something different from
            // what this column claims, and the invoices raised from them
            // overstate revenue. The window is only safe while it is short.
            //
            // Same assumption, recorded the same way, as the purchase side in
            // 2026_08_02_150003_document_unit_cost_tax_assumption.
            $table->decimal('unit_price', 15, 4);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⚠ Resolution reads by product and date on every single sale line.
            $table->index(['business_id', 'product_id', 'effective_from'], 'product_prices_resolution_index');
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
