<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ AN ORDER AND A PARCEL CARRY A WEIGHT (Joy, 11 Oct 2026). Worked out from
 * the lines, and directly editable with NO reason asked - but the worked-out
 * value is kept beside the typed one, and who typed it, so a later fee
 * analysis can tell an estimate from a correction.
 *
 * sales_orders
 * - weight_grams_auto: Σ ordered quantity x product weight, frozen at order
 *   time. ⚠ Null when any line's product has no weight: a missing weight is
 *   never zero (ruling 10).
 * - weight_grams: the typed weight. Null = the auto weight stands.
 * - weight_edited_by / weight_edited_at: who typed it, and when. Silent.
 *
 * consignments
 * - declared_weight_auto_grams: Σ challan quantity x product weight, worked
 *   out by the server at booking (a part shipment weighs only its part).
 * - declared_weight_grams: the weight the courier was told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams_auto')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->foreignId('weight_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('weight_edited_at')->nullable();
        });

        Schema::table('consignments', function (Blueprint $table) {
            $table->unsignedInteger('declared_weight_auto_grams')->nullable();
            $table->unsignedInteger('declared_weight_grams')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            $table->dropColumn(['declared_weight_auto_grams', 'declared_weight_grams']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weight_edited_by');
            $table->dropColumn(['weight_grams_auto', 'weight_grams', 'weight_edited_at']);
        });
    }
};
