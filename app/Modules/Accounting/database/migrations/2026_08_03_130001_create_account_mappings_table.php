<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 of Accounting: where a business event lands in the chart.
     *
     * Review principle 5.3 - posting is derived from dated mapping
     * configuration, never typed. Nobody enters an account code while approving
     * a payroll, and no subsystem holds an account id in its own code.
     *
     * ⚠⚠ VERSIONED BY EFFECTIVE DATE, and this is the whole reason the table
     * exists rather than a column on something. A journal posted in July must
     * resolve against the mapping that was in force in July. If a mapping were a
     * single current value, re-filing wages in December would silently change
     * what every report before it meant - and no two reports would even
     * disagree, because they would all move together.
     *
     * ⚠ `business_id` is NOT NULL and there is deliberately NO installation-wide
     * default row. A nullable business with per-business overrides reads nicely
     * and fails softly: an override with a wrong date range silently falls back
     * to the default and posts to the wrong account. One row per business means
     * a business nobody configured fails LOUDLY on its first posting.
     */
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Subsystem vocabulary, not accounting vocabulary:
            // `payroll.earning.basic`, `inventory.stock_on_hand`. The subsystem
            // knows what happened; this table knows where it lands.
            $table->string('key', 80);

            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->date('effective_from');
            // NULL means "still in force". A superseded row gets the day before
            // its successor starts.
            $table->date('effective_to')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            // ⚠ Not unique on (business, key): the same key legitimately has
            // several rows over time. Uniqueness is on where a period STARTS,
            // which is what stops two rows claiming the same opening day.
            $table->unique(['business_id', 'key', 'effective_from']);
            $table->index(['business_id', 'key', 'effective_from', 'effective_to'], 'account_mappings_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
