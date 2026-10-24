<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ IP3 meets IP14 (integration, 25 Oct 2026): a sales return's open credit
 * kept for the customer becomes their advance (kind `from_return`): Dr 1140 /
 * Cr 2150 with the customer as party, the return it came from named here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_advances', function (Blueprint $table) {
            $table->foreignId('sales_return_id')->nullable()->after('sales_order_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_return_id');
        });
    }
};
