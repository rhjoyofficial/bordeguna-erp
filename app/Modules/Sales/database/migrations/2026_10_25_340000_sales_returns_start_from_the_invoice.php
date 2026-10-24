<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B20 (Joy, 24 Oct 2026, "Sales returns"): a return starts
 * from the original invoice (or its challan), good goods go back to a chosen
 * sellable warehouse, and an exchange is a NEW order linked to the return.
 *
 *  - sales_returns.sales_invoice_id: the invoice the return was opened from
 *    (null when opened from a challan, or recorded before this);
 *  - sales_returns.stock_warehouse_id: where the good goods went, when not
 *    the warehouse they left from;
 *  - sales_orders.exchange_for_sales_return_id: the replacement order of an
 *    exchange ("Exchange for SRN-..."), never mixed into the return itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_returns', 'sales_invoice_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->foreignId('sales_invoice_id')->nullable()->after('delivery_id')->constrained('sales_invoices')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('sales_returns', 'stock_warehouse_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->foreignId('stock_warehouse_id')->nullable()->after('quarantine_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('sales_orders', 'exchange_for_sales_return_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->foreignId('exchange_for_sales_return_id')->nullable()->constrained('sales_returns')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'exchange_for_sales_return_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exchange_for_sales_return_id');
            });
        }

        foreach (['stock_warehouse_id', 'sales_invoice_id'] as $column) {
            if (Schema::hasColumn('sales_returns', $column)) {
                Schema::table('sales_returns', function (Blueprint $table) use ($column) {
                    $table->dropConstrainedForeignId($column);
                });
            }
        }
    }
};
