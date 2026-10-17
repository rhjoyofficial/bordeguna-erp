<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability musts (17 Oct 2026, TRACEABILITY-REVIEW-2026-10-17.md M2 and S10).
 *
 * 1. A BATCH CAN BE PUT ON HOLD. A suspect or recalled batch must stop being
 *    picked while it is checked. `held_at` set means "on hold": FEFO (the
 *    `consumable()` scope) skips it, so the counter, a challan, a transfer and
 *    a production run all leave it alone.
 *
 *    ⚠⚠ NOT a new `status` value. `status = active` means "this batch is still
 *    on the book" to more than twenty readers (stock value, the valuation, the
 *    low-stock screen, the ledger check), and a held batch is still ours and
 *    still worth what it cost. A status would have made held stock vanish from
 *    the valuation. Hold is a separate fact about a batch on the book.
 *
 *    `stock_batch_holds` is the history: every hold and every release, with
 *    its reason, who and when. The columns on the batch are the current state.
 *
 * 2. A TRANSFERRED BATCH REMEMBERS ITS SOURCE. A transfer makes a new batch at
 *    the destination; `source_batch_id` is the batch it left, so a trace can
 *    follow a batch across warehouses exactly. Older transfers (null here) are
 *    followed by batch number inside the transfer group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('source_batch_id')->nullable()->after('bin_id')->constrained('stock_batches')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('status');
            $table->foreignId('held_by')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
            $table->string('hold_reason', 500)->nullable()->after('held_by');
        });

        Schema::create('stock_batch_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();
            // 'hold' or 'release'
            $table->string('action', 10);
            $table->string('reason', 500);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'stock_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_holds');

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by');
            $table->dropConstrainedForeignId('source_batch_id');
            $table->dropColumn(['held_at', 'hold_reason']);
        });
    }
};
