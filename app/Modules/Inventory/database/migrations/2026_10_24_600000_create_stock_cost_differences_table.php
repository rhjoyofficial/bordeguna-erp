<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026; FINAL-PLAN 4.3 and section 9,
 * answer 2: "The Tk 10,000 rule (cost difference on sold stock up to Tk 10,000
 * posts today) is accepted").
 *
 * One row per cost difference a correction left on goods already sold or used:
 * a goods receipt reversed after part of it was sold, found stock reversed
 * after part was used, a production run reversed or corrected after part of
 * its output was sold. Past sales are never re-costed; the difference posts
 * TODAY to its own cost-of-sales head, through this row, which is the
 * journal's source (so the receipt, adjustment or run keeps exactly the
 * journals it had, and its reversal stays a plain mirror).
 *
 * `amount` is signed: positive = cost of sales goes DOWN (Dr Stock on Hand,
 * Cr the head), negative = it goes UP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cost_differences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The document the correction was made on (receipt, adjustment, run).
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 60);
            $table->date('posted_on');
            $table->decimal('quantity_used', 18, 4);
            $table->decimal('amount', 15, 2);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('note', 500);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'stock_cost_diff_source');
            $table->index(['business_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cost_differences');
    }
};
