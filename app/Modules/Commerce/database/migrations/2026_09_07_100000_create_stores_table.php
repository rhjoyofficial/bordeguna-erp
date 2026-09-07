<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store is one website: laladia.com, bionic.garden, Econic, Azmion.
 *
 * ⚠⚠ A STORE IS NOT A TENANT and it is not a brand either. The business is the
 * tenant. A brand is what the goods are sold under. A store is a SALES CHANNEL
 * with its own address on the internet, and the three genuinely differ:
 * Econic sells LaLaDia products and Bionic products from one store, so its
 * `brand_id` is deliberately null, while laladia.com sells one brand and points
 * at it.
 *
 * ⛔ `order_prefix` is unique ACROSS EVERY BUSINESS, not per business, and that
 * is not an accident. LaLaDia and Bionic share one SSLCommerz merchant and one
 * courier account, so the reference on a settlement line is the only thing that
 * says whose money it is. Two stores answering to LLD would make a shared
 * settlement unattributable, and nobody would notice until reconciliation.
 *
 * ⛔ The three legacy identifier columns exist because a per-account identifier
 * written as a code literal has already cost this group real money: the fork
 * inherited LaLaDia's robots.txt and bionic.garden spent months telling crawlers
 * its sitemap lived on laladia.com. They are nullable, and null means "derive
 * from the store key", so a NEW store cannot silently inherit an old one's name.
 * They are filled in only for the original shop, whose live cookies and webhook
 * subscribers already carry the legacy value and would break if it changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Null on a multi-brand store (Econic). Set on a single-brand one.
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            $table->string('key', 40);
            $table->string('name');

            // ⛔ Global, not per business. See the note above.
            $table->string('order_prefix', 10);

            // Which price group a visitor is charged at. ADR-005: a website is
            // B2C only and each website IS a price group, so this is a single
            // value per store and never a per-customer decision.
            $table->string('price_group', 20)->default('retail');

            // ADR-004. `status_based` is the default because the goods are made
            // to order and a web shop that refused to sell at zero would refuse
            // most of what these businesses do.
            $table->string('availability_policy', 20)->default('status_based');

            // Filled by slice 1.2. Null means no theme chosen yet, which is why
            // a store starts as a draft.
            $table->string('theme_key', 40)->nullable();

            $table->string('status', 20)->default('draft');

            // Whole features a store may not want. Bionic runs with no wishlist.
            $table->json('features')->nullable();

            // ⛔ Nullable overrides, null means derive from the key.
            $table->string('referral_cookie_name', 60)->nullable();
            $table->string('webhook_signature_header', 60)->nullable();
            $table->string('coupon_storage_key', 60)->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'key']);
            $table->unique('order_prefix');
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
