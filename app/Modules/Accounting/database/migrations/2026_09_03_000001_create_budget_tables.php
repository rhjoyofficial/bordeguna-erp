<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets (Module 2, Slice 2; spec §21-§22): the plan the actuals are judged
 * against.
 *
 * One budget per business per fiscal year (July-June here); a line is one
 * account in one month, stored as the first-of-month DATE so the actuals
 * query joins on plain date bounds instead of a (year, month) pair that
 * somebody eventually compares across the fiscal-year boundary wrongly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The STARTING calendar year: 2026 means July 2026 - June 2027.
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'fiscal_year']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('month'); // always the first of the month
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
