<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock reservations (Phase 2, 17 Oct 2026, ChatGPT ruling Q1 and Q3).
 *
 * An APPROVED staff purchase or owner withdrawal names the exact FIFO batch
 * it will be issued from, so the price frozen at approval is the cost of the
 * batch that really leaves. Until handover that batch is held: FIFO
 * consumption and every "on hand" figure subtract the open reservations, so
 * a counter sale cannot take the batch out from under an approved request.
 *
 *   stock_reservations   one row per batch held: how much, for which document
 *                        (reference_type / reference_id), why, by whom; released
 *                        when the document is handed over, cancelled or sent
 *                        back for re-approval (released_at set, row kept as
 *                        evidence)
 *
 * ⚠ COORDINATED between builders E (staff purchase) and F (owner withdrawal):
 * both branches create this same file with these same columns, so the merge
 * keeps one copy. Additive, MySQL 8 and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_reservations')) {
            return;
        }

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            // staff_purchase, owner_withdrawal: the kind of document holding the stock.
            $table->string('reason', 32);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['stock_batch_id', 'released_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
