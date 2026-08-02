<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C-INV-4 and C-SC-5: normal adjustments belong to the Inventory Manager,
     * high-value ones to the Founder, and ⚠ Joy was explicit that the threshold
     * must be CONFIGURABLE and never hardcoded.
     *
     * ⚠ Deliberately a narrow table with named columns rather than a general
     * key-value store. Those become junk drawers, and a threshold that decides
     * who may authorise the destruction of inventory value should not live in a
     * bag of strings.
     *
     * ⚠⚠ NULL means "every adjustment needs approval". An unset threshold must
     * never read as unlimited - safe by default is the whole point, and a
     * constant somebody guessed too high is how a write-off slips through.
     */
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->decimal('adjustment_approval_threshold', 15, 4)->nullable();
            $table->timestamps();

            $table->unique('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_settings');
    }
};
