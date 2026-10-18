<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase cost types, allocation bases and the payable bill (18 Oct 2026,
 * Joy's decision D-A, IAS 2 aligned).
 *
 *  1. landed_cost_lines.default_treatment: the default that applied WHEN the
 *     line was saved. ⚠ The defaults changed today (carriage inward and
 *     unloading labour now go into the stock cost), and a line saved on 17 Oct
 *     under the old table must not start to read as an "override" with no
 *     reason. Existing rows are stamped with the 17 Oct table (packaging
 *     capitalised, everything else expensed): a record of what the default
 *     WAS, not a remapping. Treatments, amounts, allocations and journals are
 *     not touched (PROSPECTIVE only).
 *  2. landed_cost_allocations.basis_quantity / basis_unit: the figure each
 *     batch's share was computed from (units, grams or taka), kept as evidence
 *     beside the share, now that a line may spread by quantity or weight.
 *  3. supplier_bills.landed_cost_id: a landed cost owed to a supplier is
 *     recorded as that supplier's BILL when it posts, so the normal "Pay a
 *     supplier" screen settles it like any other bill (finding P3: the payable
 *     existed in the ledger and nothing could pay it).
 *
 * Additive; MySQL 8 and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('landed_cost_lines', 'default_treatment')) {
            Schema::table('landed_cost_lines', function (Blueprint $table) {
                $table->string('default_treatment', 16)->nullable()->after('treatment');
            });
        }

        // The 17 Oct seed table, for the lines that were saved under it.
        DB::table('landed_cost_lines')->whereNull('default_treatment')->where('cost_type', 'packaging')->update(['default_treatment' => 'capitalize']);
        DB::table('landed_cost_lines')->whereNull('default_treatment')->update(['default_treatment' => 'expense']);

        if (! Schema::hasColumn('landed_cost_allocations', 'basis_quantity')) {
            Schema::table('landed_cost_allocations', function (Blueprint $table) {
                $table->decimal('basis_quantity', 20, 4)->nullable()->after('quantity_consumed');
                $table->string('basis_unit', 16)->nullable()->after('basis_quantity');
            });
        }

        if (! Schema::hasColumn('supplier_bills', 'landed_cost_id')) {
            Schema::table('supplier_bills', function (Blueprint $table) {
                $table->foreignId('landed_cost_id')->nullable()->after('purchase_order_id')
                    ->constrained('landed_costs', indexName: 'supplier_bills_landed_cost_fk')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('supplier_bills', 'landed_cost_id')) {
            Schema::table('supplier_bills', function (Blueprint $table) {
                $table->dropForeign('supplier_bills_landed_cost_fk');
                $table->dropColumn('landed_cost_id');
            });
        }

        if (Schema::hasColumn('landed_cost_allocations', 'basis_quantity')) {
            Schema::table('landed_cost_allocations', function (Blueprint $table) {
                $table->dropColumn(['basis_quantity', 'basis_unit']);
            });
        }

        if (Schema::hasColumn('landed_cost_lines', 'default_treatment')) {
            Schema::table('landed_cost_lines', function (Blueprint $table) {
                $table->dropColumn('default_treatment');
            });
        }
    }
};
