<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a shop says about itself to a machine.
 *
 * ⭐⭐ `is_indexable` DEFAULTS TO FALSE AND THAT IS THE WHOLE POINT. Every shop
 * spends its early life on a preview hostname, and a preview shop indexed under
 * the company's own name is very hard to undo: the wrong URLs enter search
 * results, they outrank the real ones for a while, and removing them is a
 * request to a third party rather than a change anybody here can make. So a shop
 * is invisible to crawlers until somebody deliberately says otherwise, on the
 * day it is live on the address it means to keep.
 *
 * ⚠ Deliberately SEPARATE from `status`. A store can be live - really serving
 * the public - on a preview hostname while a cutover is rehearsed, and those are
 * two different decisions. Deriving one from the other would mean the rehearsal
 * could not happen without being indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // The fallbacks a page uses when it sets none of its own. A page
            // still overrides them; this stops every untitled page sharing the
            // browser's idea of a title.
            $table->string('seo_title')->nullable()->after('theme_key');
            $table->string('seo_description', 320)->nullable()->after('seo_title');

            $table->boolean('is_indexable')->default(false)->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description', 'is_indexable']);
        });
    }
};
