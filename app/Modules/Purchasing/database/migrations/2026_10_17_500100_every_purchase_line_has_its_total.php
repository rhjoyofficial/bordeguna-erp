<?php

use App\Modules\Purchasing\Support\PurchaseLineBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The constraint, one migration AFTER the backfill (17 Oct 2026).
 *
 * ⚠ Refuses rather than guesses. If any line still has no total, something
 * wrote a line between the two migrations without one, and the right answer
 * is to say so and stop, not to make the column mandatory over a hole.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (($remaining = PurchaseLineBackfill::remaining()) > 0) {
            throw new RuntimeException(
                "{$remaining} purchase order line(s) still have no line total. Run the backfill (PurchaseLineBackfill::run) and migrate again."
            );
        }

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->bigInteger('line_total_paisa')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->bigInteger('line_total_paisa')->nullable()->change();
        });
    }
};
