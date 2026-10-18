<?php

use App\Modules\Purchasing\Support\PurchaseOrderDateBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The constraint, one migration AFTER the backfill (18 Oct 2026, D-G).
 *
 * ⚠ Refuses rather than guesses. If any order still has no order date,
 * something wrote one between the two migrations without it, and the right
 * answer is to say so and stop, not to make the column mandatory over a hole.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (($remaining = PurchaseOrderDateBackfill::remaining()) > 0) {
            throw new RuntimeException(
                "{$remaining} purchase order(s) still have no order date. Run the backfill (PurchaseOrderDateBackfill::run) and migrate again."
            );
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('order_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('order_date')->nullable()->change();
        });
    }
};
