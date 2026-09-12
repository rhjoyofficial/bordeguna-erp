<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thing somebody put in a basket.
 *
 * ⭐⭐ THERE IS NO PRICE COLUMN, AND THAT ABSENCE IS THE ENFORCEMENT, exactly as
 * on `store_products`. A cart line records WHAT SOMEBODY CHOSE, never what it
 * costs. Every figure is resolved at read time through
 * `CataloguePublishing::priceFor`, the one method the publish gate and the
 * storefront already share.
 *
 * Storing the price here would buy nothing and cost two things. It would be a
 * second opinion about money, editable by whoever can reach this row and
 * invisible to every report that reads `product_prices`. And it would be a
 * figure quoted an hour ago, so a price corrected in the ERP would keep selling
 * at the old one for as long as somebody left a tab open.
 *
 * ⚠ It also means an abandoned-cart value is worth today's price rather than a
 * frozen one, which is the number anybody chasing that basket actually wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            // ⚠ restrictOnDelete, matching store_products. A product cannot be
            // deleted out from under a live basket; it is taken off sale first.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // ⚠ Integer, not decimal. A website sells whole jars. The ERP's own
            // documents deal in decimal quantities because a warehouse moves
            // 2.5 kg of sugar, but a shopper choosing 2.5 of a thing is a bug
            // in the form, not a requirement.
            $table->unsignedInteger('quantity');

            $table->timestamps();

            // One line per product. Adding the same jar twice is a bigger
            // quantity, not a second row somebody has to reconcile.
            $table->unique(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_lines');
    }
};
