<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            // Running total across every PurchaseReturnLine against this line —
            // incremented by RecordPurchaseReturn, never edited directly.
            // quantity_received - quantity_returned is the net stock still
            // attributed to this line, and must reach zero before the order
            // can be cancelled.
            $table->decimal('quantity_returned', 15, 4)->default(0)->after('quantity_received');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_returned');
        });
    }
};
