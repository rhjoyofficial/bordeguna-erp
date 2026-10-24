<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B1 (25 Oct 2026), Joy's purchase screen sections 1 and 3:
 *
 *   purchase_orders.purchasing_employee_id   R40: the HR employee who handled
 *       the purchase ("crucial for tracking if they carried the cash"). The
 *       older `buyer_id` (a login) stays as it was and is still shown.
 *   purchase_order_charges.payee_name,
 *   landed_cost_lines.payee_name             R49: a one-off person paid for a
 *       cost (a truck owner, a restaurant) who is not a registered supplier,
 *       written on a cost paid from a money account.
 *
 * Nothing is backfilled: every existing row simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('purchasing_employee_id')->nullable()->after('buyer_id')
                ->constrained('employees', 'id', 'po_purchasing_employee_fk')->nullOnDelete();
        });

        Schema::table('purchase_order_charges', function (Blueprint $table) {
            $table->string('payee_name', 120)->nullable()->after('money_account_id');
        });

        Schema::table('landed_cost_lines', function (Blueprint $table) {
            $table->string('payee_name', 120)->nullable()->after('money_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('landed_cost_lines', fn (Blueprint $table) => $table->dropColumn('payee_name'));
        Schema::table('purchase_order_charges', fn (Blueprint $table) => $table->dropColumn('payee_name'));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign('po_purchasing_employee_fk');
            $table->dropColumn('purchasing_employee_id');
        });
    }
};
