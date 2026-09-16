<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management slice A3 (plan §4d): a stock-take of one place, and the
 * gaps it finds.
 *
 * ⭐ Starting a count SNAPSHOTS what the register expects at the place, so a
 * hand-over recorded during the count does not move the goalposts. Every line
 * is marked explicitly; opening an item's page proves nothing.
 *
 * ⚠ One open count per place: `open_place_key` is "o4" / "w1" while open and
 * NULL once closed, so a unique index allows any number of closed counts and
 * exactly one open one (MySQL lets NULLs repeat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            // open | closed
            $table->string('status', 10)->default('open');
            $table->string('open_place_key', 20)->nullable()->unique();
            $table->text('note')->nullable();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('asset_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_item_id')->constrained()->restrictOnDelete();
            // Expected at the place when the count started, and how many.
            $table->boolean('expected');
            $table->unsignedInteger('expected_quantity');
            // NULL until somebody marks it.
            $table->boolean('found')->nullable();
            $table->unsignedInteger('found_quantity')->nullable();
            $table->string('condition', 12)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['asset_count_id', 'asset_item_id']);
        });

        Schema::create('asset_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_item_id')->constrained()->restrictOnDelete();
            // missing | unexpected | short | over
            $table->string('kind', 12);
            $table->unsignedInteger('quantity');
            // What the item's status was before the count said Missing.
            $table->string('status_before', 24)->nullable();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            // open | resolved
            $table->string('state', 10)->default('open');
            // located | wrongly_recorded | found_damaged | confirmed_lost
            $table->string('resolution', 20)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state']);
            $table->index(['asset_item_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_discrepancies');
        Schema::dropIfExists('asset_count_lines');
        Schema::dropIfExists('asset_counts');
    }
};
