<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C-SC-2. Once a period is officially closed, no ordinary user may post or
     * backdate inventory into it.
     *
     * A backdated movement landing before a date somebody has already reported
     * on makes that report wrong, silently, and nothing else in the system would
     * ever say so. That is the entire reason this table exists.
     *
     * ⚠ One row per business, not a row per period. The question being asked is
     * always "what is the earliest date I may still write to?", and a single
     * moving boundary answers it without a table that grows forever and without
     * gaps between periods that nobody notices.
     *
     * ⚠⚠ `is_unlocked` is deliberately NOT just a boolean somebody flips. The
     * Founder unlocking a closed period is an event with a name and a reason
     * attached, and both the unlock and the relock are written to the activity
     * log - Joy's requirement, and the failure mode of every quiet maintenance
     * toggle ever built.
     */
    public function up(): void
    {
        Schema::create('inventory_period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Inclusive. "Closed through 31 July" includes the 31st, or the last
            // day of every period stays quietly open and the lock is off by one
            // for its entire life.
            $table->date('closed_through');
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();

            $table->boolean('is_unlocked')->default(false);
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('unlock_note')->nullable();

            $table->timestamps();

            $table->unique('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_period_locks');
    }
};
