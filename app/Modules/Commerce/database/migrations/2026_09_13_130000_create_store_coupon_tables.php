<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons a shop offers, and the code a basket holds. Website Phase 2, 2.5b.
 *
 * ⭐ The same fields LaLaDia's coupons carry today (percent or fixed, minimum
 * spend, total and per-customer limits, dates, active, public, a scope of all /
 * products / combos, free delivery and a gift), so nothing a shop relies on is
 * lost at cutover.
 *
 * ⛔⛔ THERE IS NO `used_count` COLUMN, and that absence is Joy's rule (13 Sep):
 * usage is COUNTED from the sales orders that carry the code, and a CANCELLED
 * order does not use a coupon up. A stored counter is incremented when an order
 * is placed and never learns that 66% of cash-on-delivery orders were then
 * cancelled - so a one-per-customer code would be spent by an order that never
 * happened.
 *
 * ⛔ And the basket holds the CODE, never the discount, for the reason a cart
 * line holds no price: the figure is worked out every time the basket is read.
 *
 * ⚠ Per STORE. Two shops in one business are two brands with their own
 * promotions, and a code printed on a LaLaDia flyer must not work on Bionic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // ⚠ Stored UPPERCASE and matched that way: a customer typing
            // "welcome10" off a flyer has not typed a different code.
            $table->string('code', 40);

            $table->string('type', 10);
            $table->decimal('value', 15, 2);
            $table->decimal('min_purchase', 15, 2)->nullable();

            // Null means no limit.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('limit_per_customer')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);

            $table->string('applies_to', 10)->default('all');
            $table->boolean('is_free_delivery')->default(false);

            // ⚠ restrictOnDelete: a gift somebody has been promised cannot be
            // deleted out from under the coupon that promises it.
            $table->foreignId('gift_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('gift_quantity')->default(1);

            $table->timestamps();

            $table->unique(['store_id', 'code']);
        });

        Schema::create('store_coupon_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unique(['store_coupon_id', 'product_id']);
        });

        Schema::create('store_coupon_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_combo_id')->constrained()->cascadeOnDelete();
            $table->unique(['store_coupon_id', 'store_combo_id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->string('coupon_code', 40)->nullable()->after('delivery_district');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });

        Schema::dropIfExists('store_coupon_combos');
        Schema::dropIfExists('store_coupon_products');
        Schema::dropIfExists('store_coupons');
    }
};
