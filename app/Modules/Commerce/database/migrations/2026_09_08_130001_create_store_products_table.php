<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ERP products appear on which store, and how they are presented there.
 *
 * ⛔⛔ WHAT IS ABSENT IS THE ENFORCEMENT, exactly as in the section contract.
 *
 * There is NO price column. ADR-005 says a website is B2C and each website IS a
 * price group, so what a visitor pays comes from the ordinary price list read at
 * the store's group. A price here would be a second opinion about money,
 * editable from a content screen and invisible to every report that reads
 * `product_prices`.
 *
 * There is NO stock, availability or lead-time column. Availability is a policy
 * on the store (ADR-004, status_based because the goods are made to order), not
 * a number a website states about a warehouse.
 *
 * There is NO name, unit, SKU or category. Those belong to the product master
 * and have one owner. `title_override` and `summary_override` are presentation:
 * what the shop CALLS the thing, not what the thing IS. A store that overrides
 * nothing still has a complete catalogue, which is what makes publishing cheap.
 *
 * ⚠ `published_at` rather than a boolean, so the catalogue can answer when a
 * product went on sale. A draft row is a product somebody is preparing, and it
 * is invisible to the public by construction rather than by a filter somebody
 * has to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // ⚠ restrictOnDelete, NOT cascade. A product cannot be deleted out
            // from under a live storefront page silently; the shop takes it off
            // sale first. Same two-decisions rule as deleting an image a page
            // still uses.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // ⚠⚠ THE PUBLIC URL. Unique PER STORE, never globally: two shops in
            // this group may both sell honey and both want /products/honey, and
            // a global unique would hand the second one a worse address for no
            // reason. It is also why a cutover can keep every URL it has today.
            $table->string('slug', 200);

            $table->string('title_override')->nullable();
            $table->string('summary_override', 500)->nullable();

            // ⚠ From THIS store's own library, never a typed URL. Same rule as a
            // page section: a dangling reference, or another shop's photography,
            // is what the media library exists to make impossible.
            $table->foreignId('image_media_id')->nullable()
                ->constrained('store_media')->nullOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // One row per product per store. Publishing the same product twice
            // is not a richer catalogue, it is two pages competing for one
            // search result.
            $table->unique(['store_id', 'product_id']);
            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
