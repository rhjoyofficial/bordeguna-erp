<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1.2 of Accounting: one period control for the whole system.
     *
     * ⚠⚠ The lock was built for stock and lived in Inventory. A closed month has
     * to mean the same thing to a salary payment, a supplier bill and a journal
     * as it does to a goods receipt - and the alternative was Accounting
     * introducing a SECOND lock that could disagree with the first, which is a
     * worse outcome than the single-module one.
     *
     * ⚠ Generalised, not duplicated. Same table, same inclusive boundary, same
     * Founder-only temporary unlock, same full audit - moved to Foundation and
     * renamed to say what it actually governs.
     *
     * ℹ Production holds zero rows, so this rename carries no data risk. It is
     * still written as a rename rather than a drop-and-create, because the next
     * environment may not be so empty.
     */
    public function up(): void
    {
        Schema::rename('inventory_period_locks', 'accounting_periods');
    }

    public function down(): void
    {
        Schema::rename('accounting_periods', 'inventory_period_locks');
    }
};
