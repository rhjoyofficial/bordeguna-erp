<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * C-ACC-1, discharged honestly rather than built.
     *
     * Joy 2026-08-02: Bor De Guna is not implementing VAT accounting yet, so do
     * not block Inventory on a tax decision - but DOCUMENT the assumption the
     * system currently makes, in the code and the UI, and leave the full
     * treatment to Accounting.
     *
     * ⚠⚠ The assumption, stated: `unit_cost` is THE AMOUNT PAID PER UNIT,
     * INCLUSIVE of whatever taxes were charged, with no tax component separated
     * out. Inventory is therefore valued GROSS.
     *
     * Why this matters enough to be a migration rather than a comment: movements
     * are immutable, so every batch created before the decision carries the
     * ambiguity permanently. If Accounting later determines VAT is recoverable,
     * inventory must be revalued net - and that is now possible, because A1
     * shipped in the same slice.
     *
     * This migration changes no data. It exists to put the sentence in the
     * schema history where a future reader will look.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE purchase_order_lines MODIFY unit_cost DECIMAL(15,4) NOT NULL COMMENT 'Amount paid per unit, INCLUSIVE of taxes. No tax component is separated; inventory is valued gross. See INVENTORY-ACCOUNTING-BOUNDARY-REVIEW.md C-ACC-1.'");
        DB::statement("ALTER TABLE stock_batches MODIFY unit_cost DECIMAL(15,4) NOT NULL COMMENT 'Cost per unit, INCLUSIVE of taxes. See INVENTORY-ACCOUNTING-BOUNDARY-REVIEW.md C-ACC-1.'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE purchase_order_lines MODIFY unit_cost DECIMAL(15,4) NOT NULL COMMENT ""');
        DB::statement('ALTER TABLE stock_batches MODIFY unit_cost DECIMAL(15,4) NOT NULL COMMENT ""');
    }
};
