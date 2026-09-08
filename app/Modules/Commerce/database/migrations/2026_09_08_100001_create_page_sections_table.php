<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One typed block on a page, in order.
 *
 * ⛔⛔ `type_key` AND `type_version` ARE BOTH STORED, and the version is not
 * decoration. A theme declares the maximum version of each type it can render,
 * so a page holding `story.split@1` must keep rendering on the day
 * `story.split@2` is registered. Storing only the key would silently reinterpret
 * every existing section the moment a contract changed, which is the difference
 * between an upgrade and an outage.
 *
 * ⚠ `payload` is validated against the section type on the way in, by
 * `SectionPayloadValidator` and nothing else. A theme cannot loosen it, cannot
 * require a field the contract does not have, and cannot make it mean something
 * else, because two themes rendering this row must show the same facts.
 *
 * ⚠ `position` is an integer the composer rewrites as a contiguous run on every
 * reorder, rather than a float wedged between neighbours. Gaps and ties are how
 * an ordering silently stops being an ordering.
 *
 * ⚠ `is_hidden` is not deletion. Taking a section off the page while keeping its
 * content is the ordinary editorial act: the seasonal product that is out of
 * stock, the certification whose scan has expired. Deleting to hide it means
 * retyping it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();

            $table->string('type_key', 60);
            $table->unsignedSmallInteger('type_version')->default(1);

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_hidden')->default(false);

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
