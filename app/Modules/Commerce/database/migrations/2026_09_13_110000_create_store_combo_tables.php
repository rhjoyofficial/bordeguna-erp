<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bundles a shop sells, and bundles in a basket. Website Phase 2, slice 2.5a.
 *
 * ⭐⭐ D25 (Joy, 7 September): a combo is a STORE-LEVEL bundle of finished,
 * already-sellable products. It is not a product type, nothing is manufactured
 * for it and it holds no stock. On an order it EXPLODES into the real product
 * lines, so stock leaves against each jar and cost of goods attaches to each jar
 * - which is what keeps the books and the warehouse honest.
 *
 * ⚠⚠ `store_combos.price` IS A PRICE OUTSIDE THE PRICE LIST, and that is a
 * deliberate exception to the rule `store_products` states in capitals. It is
 * allowed for exactly one reason: D25 says a bundle may be sold for a custom
 * figure. What it is NOT allowed to do is change what a jar costs. Every part is
 * still billed at the price list's own price for the store's group, and the
 * bundle's saving is recorded as a DISCOUNT on those lines - so a report reading
 * `product_prices` still sees the true price, and the saving is visible as a
 * saving rather than hidden inside a cheaper unit price. Null means "the sum of
 * the parts", which needs no second opinion about money at all.
 *
 * ⚠ The basket keeps bundles in their OWN table rather than widening
 * `cart_lines`. A cart line is "a product and a whole number of it" and every
 * reader of that table relies on the product being there; making `product_id`
 * nullable would have asked each of them to learn a second shape, and altering
 * a column that sits under a composite unique index is exactly the change that
 * failed to roll back on MySQL in slice 2.4 (error 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // ⚠ Unique PER STORE, the rule `store_products.slug` follows, and it
            // lives under /combos/ so it can never collide with a product's.
            $table->string('slug', 200);
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();

            // ⛔ From THIS store's library, never a typed URL.
            $table->foreignId('image_media_id')->nullable()
                ->constrained('store_media')->nullOnDelete();

            // ⚠ Two places: a price a customer is shown. Null = the sum of the
            // parts at the store's price group, resolved when it is read.
            $table->decimal('price', 15, 2)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'published_at']);
        });

        Schema::create('store_combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_combo_id')->constrained()->cascadeOnDelete();

            // ⚠ restrictOnDelete, as everywhere a shop points at a product: a
            // product cannot be deleted out from under a bundle somebody sells.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // ⚠ Whole jars, the reason `cart_lines.quantity` is an integer.
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // One row per product per bundle: two jars of honey is a quantity.
            $table->unique(['store_combo_id', 'product_id']);
        });

        Schema::create('cart_combo_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            // ⚠ restrictOnDelete, NOT cascade. Deleting a bundle must not make a
            // jar vanish silently from somebody's basket, and a bundle that has
            // been ordered is history. It is taken off sale instead.
            $table->foreignId('store_combo_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'store_combo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_combo_lines');
        Schema::dropIfExists('store_combo_items');
        Schema::dropIfExists('store_combos');
    }
};
