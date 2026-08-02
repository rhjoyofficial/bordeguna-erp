<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A2: a purchase return could not find its own paperwork.
     *
     * `goods_receipt_lines` has carried `stock_movement_id` since it was built,
     * so a receipt movement traces to the receipt, the order and the supplier.
     * Return lines had no such column, so the movements sending goods BACK to a
     * supplier could not reach the return - and a credit-note journal could not
     * tell which supplier to credit except by inferring through
     * `reverses_movement_id`.
     *
     * ⚠ An accounting posting that depends on a two-hop inference is the kind
     * that silently credits the wrong supplier the first time somebody returns
     * goods received on two different orders.
     */
    public function up(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')->nullable()->after('quantity_returned')
                ->constrained('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
        });
    }
};
