<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: "recipient count above, make dynamic, default 50 is good".
 *
 * It was a constant in the controller with a comment admitting it was a default
 * nobody had chosen. Now it is a decision with a home, and changing it is a
 * setting rather than a deploy.
 *
 * ⚠ Narrow named column, not a key-value store - the same reasoning as
 * `inventory_settings`. A number that decides who may authorise spending money
 * should not live in a bag of strings.
 *
 * ⚠⚠ NULL MEANS EVERY SEND NEEDS APPROVAL. An unset threshold must never read as
 * unlimited. If somebody clears the field, the safe reading of "no threshold is
 * configured" is that nothing goes out unapproved - not that everything does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Above this many recipients, a send raises an approval instead.
            $table->unsignedInteger('approval_above')->nullable()->default(50);

            $table->timestamps();

            $table->unique('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_settings');
    }
};
