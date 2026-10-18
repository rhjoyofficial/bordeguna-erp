<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ D-B (Joy, 18 Oct 2026, "Cost price"): the proprietor buys as a customer
 * of his own at the cost of the FIFO batch the sale consumes. No equity
 * deduction, no monthly cap, normal receivable.
 *
 *  - `price_groups.price_basis`: `list` (every group so far: priced from its
 *    rows, the MRP where it falls back) or `cost` (the engine answers with
 *    the batch cost, ResolveSellingPrice). Defaulted, so no existing group
 *    changes its behaviour.
 *  - `customers.owner_user_id`: the proprietor this owner customer IS
 *    (unique where set), made from Settings > Company, never typed.
 *  - `sales_invoices.is_owner_sale` + the line flag: owner sales leave the
 *    retail KPIs by default and stay in every financial report.
 *  - The OWNER group per business, created only where no group with that
 *    code exists. ⚠ Looked up by code and never adopted: a hand-made OWNER
 *    group keeps whatever it was.
 *
 * Additive, defaulted or nullable; MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_groups', function (Blueprint $table) {
            $table->string('price_basis', 16)->default('list')->after('falls_back_to_mrp');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->after('employee_id');
            $table->unique('owner_user_id');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('is_owner_sale')->default(false)->after('is_staff_sale');
            $table->index(['business_id', 'is_owner_sale']);
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->boolean('is_owner_sale')->default(false)->after('is_staff_sale');
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $exists = DB::table('price_groups')->where('business_id', $businessId)->where('code', 'OWNER')->exists();

            if ($exists) {
                continue;
            }

            DB::table('price_groups')->insert([
                'business_id' => $businessId,
                'code' => 'OWNER',
                'name' => 'Owner (at cost)',
                'prices_include_tax' => true,
                'falls_back_to_mrp' => false,
                'price_basis' => 'cost',
                'is_active' => true,
                'note' => 'Seeded (D-B, 18 Oct 2026): the proprietor buys at the FIFO batch cost. No MRP fallback, no cap.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('price_groups')->where('code', 'OWNER')->where('price_basis', 'cost')->whereNotExists(function ($query) {
            $query->select(DB::raw(1))->from('customers')->whereColumn('customers.price_group_id', 'price_groups.id');
        })->delete();

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('is_owner_sale');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_owner_sale']);
            $table->dropColumn('is_owner_sale');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });

        Schema::table('price_groups', function (Blueprint $table) {
            $table->dropColumn('price_basis');
        });
    }
};
