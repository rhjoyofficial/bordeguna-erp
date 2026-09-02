<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accruals and prepayments (Module 2, Slice 4; spec §30-§31): the Nikola
 * case generalized. A schedule spreads one amount over N months between a
 * balance-sheet head (prepaid asset, accrued liability, deferred income) and
 * the P&L head it belongs to, one journal per month, posted by the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // prepaid_expense | accrued_expense | deferred_income
            $table->string('name');
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('target_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('total_amount', 14, 2);
            $table->date('start_month'); // first of month
            $table->unsignedTinyInteger('months');
            // The idempotency pointer: the last month (first-of-month date)
            // already released. Null = nothing released yet.
            $table->date('released_through')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('active'); // active | done | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_schedules');
    }
};
