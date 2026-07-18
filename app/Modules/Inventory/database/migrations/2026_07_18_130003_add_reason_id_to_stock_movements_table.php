<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Only populated for adjustment/transfer movements (see
            // ApplyStockAdjustment / DispatchStockTransfer) — plain
            // receipts/usages don't need a reason code, MovementReason
            // already says what kind of movement it is.
            $table->foreignId('reason_id')->nullable()->after('reason')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['reason_id']);
            $table->dropColumn('reason_id');
        });
    }
};
