<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page on a store: an address, some SEO, and an ordered list of sections.
 *
 * ⭐⭐ THIS IS THE TABLE THAT REPLACES 16 BESPOKE BLADE TEMPLATES AND 7,044
 * LINES. Every one of those templates is a page whose content was welded to its
 * markup, which is why a copy change needed a deploy, why one of them shipped
 * broken CSS that was live for a day, and why four of them could emit an empty
 * `<title>`. Content lives here; how it LOOKS lives in a theme.
 *
 * ⛔ `business_id` AND `store_id` are both stored, and that is not redundancy
 * for its own sake. Every model in this ERP fails closed through `BusinessScope`
 * and a page must too, or a forgotten `BusinessContext::set()` in a queued job
 * would read another company's unpublished content. A store cannot move between
 * businesses (the update path does not accept `business_id`), so the two cannot
 * drift, and a test asserts they agree.
 *
 * ⚠ `published_at` is a TIMESTAMP, not a boolean. "Published" and "published on
 * the 6th" are different facts, and the shops have already been bitten by the
 * boolean version of this: three articles sat as drafts and `/blog` rendered
 * zero articles, because nothing on the screen said when a page would appear.
 * A null means never published; a future date is scheduled and not yet public.
 *
 * ⚠ The slug is unique PER STORE and not globally. Two stores may both have
 * `terms`, which is the whole point of one codebase serving several shops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // ⚠ Defaulted in the DATABASE as well as the model, the way `stores`
            // does it. `custom` is the least-privileged type in the enum, so a
            // row that somehow arrives without one is an ordinary page with its
            // own address rather than a second home page.
            $table->string('type', 20)->default('custom');
            $table->string('slug', 190);
            $table->string('title');

            // ⚠ Nullable, and the storefront falls back to `title`. They are
            // separate fields because `products.meta_title` was admin-editable
            // on the shops for a year and NEVER RENDERED, so every SEO title
            // typed into that box was silently discarded.
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'type']);
            $table->index(['business_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
