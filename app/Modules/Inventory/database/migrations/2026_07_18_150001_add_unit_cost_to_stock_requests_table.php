<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            // Required only when type=adjustment and direction=increase — an
            // increase creates a new stock batch, which needs a cost. Without
            // this, ApproveStockRequest has no unit_cost to hand ApplyStockAdjustment
            // and every increase-direction request fails on approval.
            $table->decimal('unit_cost', 15, 4)->nullable()->after('reason_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
