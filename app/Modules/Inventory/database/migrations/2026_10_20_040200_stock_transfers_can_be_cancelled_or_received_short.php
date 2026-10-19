<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a transfer still on the road can be
 * cancelled (the stock goes back onto the batches it left), or received short
 * with the shortage and its reason recorded (FINAL-PLAN 3.3).
 *
 * `status` is a string, so `cancelled` needs no schema change; these columns
 * say who cancelled and why, and how much of a short delivery arrived. The
 * shortage itself is a stock correction (an inventory adjustment whose source
 * is the transfer), so it meets the approval limit like any other loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
            $table->decimal('received_quantity', 15, 4)->nullable()->after('cancel_reason');
            $table->decimal('shortage_quantity', 15, 4)->nullable()->after('received_quantity');
            $table->text('shortage_reason')->nullable()->after('shortage_quantity');

            $table->foreign('cancelled_by', 'stock_transfers_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign('stock_transfers_cancelled_by_fk');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason', 'received_quantity', 'shortage_quantity', 'shortage_reason']);
        });
    }
};
