<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rule #6 (Joy, 14 Oct 2026, after the 13 Oct audit): a buyer whose price
// group has no price list is quoted at the RETAIL list instead of being
// refused, and the quote line keeps where its price came from so the quote
// never does it silently:
//   list             the buyer's own list (their price, their group's, or theirs by MRP)
//   retail_fallback  the retail list, because the buyer's group has none (or a lead)
//   quoted           a price typed by someone allowed to quote one
// Lines written before today stay null: nobody recorded their source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->string('price_source', 20)->nullable()->after('price_note');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropColumn('price_source');
        });
    }
};
