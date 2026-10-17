<?php

use App\Modules\Purchasing\Support\PurchaseLineBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 part A (17 Oct 2026, ChatGPT ruling Q1): a purchase line stores the
 * money it is worth, in paisa, and remembers whether the price or the total
 * was typed.
 *
 * ⚠⚠ WHY A STORED TOTAL. Joy buys "3,000 units for 100,000". The only rate
 * that fits four places is 33.3333, and 3,000 x 33.3333 is 99,999.90: ten
 * paisa short on the order, in GRNI, and in the variance against the bill,
 * on every such line. The total the supplier agreed is the truth; the rate
 * is a six-place derivation shown beside it.
 *
 * ⚠ Additive and reversible: the new column is nullable here, filled by the
 * application's own Money arithmetic in batches of 500, and made NOT NULL by
 * the next migration only once every row has a value (live-migration rule:
 * compatibility code before constraints). Nothing historical is rewritten.
 *
 * ⚠ `unit_cost` widens from four to six places on both tables. Widening loses
 * nothing: every stored value is unchanged, read back with two more zeros.
 */
return new class extends Migration
{
    private const UNIT_COST_COMMENT = 'Amount paid per purchase unit, INCLUSIVE of taxes (C-ACC-1). Six places since 17 Oct 2026: derived from the stored line total when that was typed, never used to rebuild it.';

    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 6)->comment(self::UNIT_COST_COMMENT)->change();
            // Whole paisa, the Money convention: a ledger ties on integers.
            $table->bigInteger('line_total_paisa')->nullable()->after('unit_cost');
            // rate | total: which of the two figures was typed (PurchaseEntryMode).
            $table->string('entry_mode', 8)->default('rate')->after('line_total_paisa');
        });

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 6)->change();
            $table->string('entry_mode', 8)->default('rate')->after('line_total');
        });

        PurchaseLineBackfill::run(500);
    }

    public function down(): void
    {
        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
            $table->decimal('unit_cost', 15, 4)->change();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn(['line_total_paisa', 'entry_mode']);
            $table->decimal('unit_cost', 15, 4)->change();
        });
    }
};
