<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts (Module 3, Slice 1): the accountability wrapper around the
 * till. The shift POSTS NOTHING - money still moves only through the
 * documents that already move it; the shift records custody, and the
 * over/short is computed at close, never typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_count', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('over_short', 14, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('open'); // open | closed
            $table->timestamps();
            $table->index(['money_account_id', 'status']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->string('type', 10); // cash_in | cash_out
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_shifts');
    }
};
