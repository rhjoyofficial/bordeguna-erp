<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): damaged goods a customer
 * sends back can go to a Not for sale (quarantine) warehouse instead of being
 * written off at once. They stay stock, at the batch cost they left at, until
 * they are moved back to stock or written off as Damaged returns.
 *
 *  - sales_return_lines.quantity_to_quarantine: how much of the line went
 *    there (a third share beside back to stock and written off).
 *  - sales_returns.quarantine_warehouse_id: where it went.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_return_lines', 'quantity_to_quarantine')) {
            Schema::table('sales_return_lines', function (Blueprint $table) {
                $table->decimal('quantity_to_quarantine', 15, 4)->default(0)->after('quantity_written_off');
            });
        }

        if (! Schema::hasColumn('sales_returns', 'quarantine_warehouse_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->foreignId('quarantine_warehouse_id')->nullable()->after('delivery_id')->constrained('warehouses')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_returns', 'quarantine_warehouse_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->dropConstrainedForeignId('quarantine_warehouse_id');
            });
        }

        if (Schema::hasColumn('sales_return_lines', 'quantity_to_quarantine')) {
            Schema::table('sales_return_lines', function (Blueprint $table) {
                $table->dropColumn('quantity_to_quarantine');
            });
        }
    }
};
