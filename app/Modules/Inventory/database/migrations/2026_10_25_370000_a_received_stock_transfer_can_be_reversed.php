<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a transfer that has arrived
 * can be reversed through the corrections engine (the goods leave the
 * destination batches and go back onto the source batches they left, at the
 * same cost). The transfer stays, `received`, marked with who reversed it, on
 * which day and why; `status` is not touched, so no screen or report that
 * reads it changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('shortage_reason');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');

            $table->foreign('reversed_by', 'stock_transfers_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign('stock_transfers_reversed_by_fk');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
    }
};
