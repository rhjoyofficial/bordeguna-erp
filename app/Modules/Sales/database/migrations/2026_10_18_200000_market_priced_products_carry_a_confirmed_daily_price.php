<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Market-priced goods (Phase 2 / 1B, 17 Oct 2026; ChatGPT ruling Q4, Q6).
 *
 * Fish and daily groceries have no printed MRP: their price is set each
 * morning from what the latest lot cost. Until now such a product had no
 * price at all (hidden on the counter, refused on an order) or was given an
 * MRP it does not have.
 *
 * - products.pricing_mode: `fixed_mrp` (the engine as it was) or `market`.
 *   ⚠ Explicit, because `mrp IS NULL` cannot tell a market product from a
 *   packed item whose MRP was never typed. Nullable first, backfilled to
 *   fixed_mrp, and the model reads null as fixed_mrp.
 * - categories.market_* and products.market_*: the markup rules. Category
 *   defaults, SKU override (null = inherit). DEFINITIONS, so two developers
 *   cannot build two formulas from the same names:
 *     markup_percent      = markup ON COST            (C x (1 + m/100))
 *     min_margin_percent  = gross margin ON SELLING   (C / (1 - g/100))
 *     rounding_step       = the price increment (Tk 1, 5, 10), rounded UP
 * - product_price_history: one immutable row per confirmed price change:
 *   business, product, channel, source batch and receipt line, cost
 *   snapshot, prior and new price, the formula snapshot, override reason,
 *   who and when. Never updated, never deleted.
 * - market_prices: today's price per product/business/channel, ONE row each
 *   (unique index). The engine still prices from product_prices (one live
 *   RETAIL row per confirmation, the previous one closed); this table is the
 *   "since HH:MM" badge, the counter's one-query lookup, and the uniqueness
 *   the ruling asked for.
 * - product_prices.market_price_history_id: which confirmation wrote the row,
 *   so the resolver can tell a confirmed market price from a hand-typed one.
 * - goods_receipt_lines.market_price_status: `confirmed` or `unconfirmed` on
 *   a market product's line, so a receipt that proposed a price nobody
 *   confirmed says so for ever.
 *
 * Additive only. MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('pricing_mode', 20)->nullable()->after('mrp');
            $table->decimal('market_markup_percent', 7, 4)->nullable()->after('pricing_mode');
            $table->decimal('market_min_margin_percent', 7, 4)->nullable()->after('market_markup_percent');
            $table->decimal('market_rounding_step', 10, 2)->nullable()->after('market_min_margin_percent');
        });

        DB::table('products')->whereNull('pricing_mode')->update(['pricing_mode' => 'fixed_mrp']);

        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('market_markup_percent', 7, 4)->nullable()->after('code');
            $table->decimal('market_min_margin_percent', 7, 4)->nullable()->after('market_markup_percent');
            $table->decimal('market_rounding_step', 10, 2)->nullable()->after('market_min_margin_percent');
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20)->default('retail');
            $table->foreignId('price_group_id')->nullable()->constrained('price_groups')->nullOnDelete();
            // Where the price came from: a goods receipt line (and the batch it
            // created), or a manual reprice from the prices screen.
            $table->string('source', 20);
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->decimal('cost_snapshot', 15, 4)->nullable();
            $table->decimal('prior_price', 15, 4)->nullable();
            $table->decimal('suggested_price', 15, 4)->nullable();
            $table->decimal('new_price', 15, 4);
            // The formula as it stood: what m, g and the step were, resolved
            // from the SKU override or the category default at that moment.
            $table->decimal('markup_percent', 7, 4)->nullable();
            $table->decimal('min_margin_percent', 7, 4)->nullable();
            $table->decimal('rounding_step', 10, 2)->nullable();
            $table->boolean('is_override')->default(false);
            $table->string('override_reason', 500)->nullable();
            // The live product_prices row this confirmation wrote.
            $table->foreignId('product_price_id')->nullable()->constrained('product_prices')->nullOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at');
            $table->timestamps();

            $table->index(['business_id', 'product_id', 'confirmed_at']);
        });

        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('retail');
            $table->decimal('unit_price', 15, 4);
            $table->decimal('cost_snapshot', 15, 4)->nullable();
            $table->foreignId('product_price_id')->nullable()->constrained('product_prices')->nullOnDelete();
            $table->foreignId('product_price_history_id')->nullable()->constrained('product_price_history')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('since_at');
            $table->timestamps();

            // ⚠⚠ ONE ACTIVE PRICE per product, business and channel. Two
            // receipts of the same fish confirmed in the same minute race on
            // this row; the last confirmed wins and both are in the history.
            $table->unique(['business_id', 'product_id', 'channel'], 'market_prices_one_per_channel');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->foreignId('market_price_history_id')->nullable()->after('created_by')
                ->constrained('product_price_history')->nullOnDelete();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->string('market_price_status', 20)->nullable()->after('stock_movement_id');
            $table->foreignId('market_price_history_id')->nullable()->after('market_price_status')
                ->constrained('product_price_history')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_price_history_id');
            $table->dropColumn('market_price_status');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_price_history_id');
        });

        Schema::dropIfExists('market_prices');
        Schema::dropIfExists('product_price_history');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['market_markup_percent', 'market_min_margin_percent', 'market_rounding_step']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'market_markup_percent', 'market_min_margin_percent', 'market_rounding_step']);
        });
    }
};
