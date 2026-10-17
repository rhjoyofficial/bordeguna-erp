<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff purchase at cost, Phase 2 (ChatGPT ruling Q2, 17 Oct 2026).
 *
 *  - `customers.employee_id`: each employee who buys gets ONE customer row of
 *    type `staff`, created on the first request, so the sale posts to that
 *    employee's receivable and not to the walk-in row (unique where set).
 *  - `sales_invoices.is_staff_sale` + `sales_invoice_lines.is_staff_sale`:
 *    staff sales are flagged so the retail KPIs leave them out by default
 *    while the financial and audit reports keep them. On the line,
 *    `list_price` carries the cap reference price and `unit_price` the staff
 *    price, so "reference minus price" is one query.
 *  - `customer_receipts.kind` + `payroll_run_id`, and `money_account_id`
 *    becomes nullable: the payroll settlement is a NON-CASH, invoice-linked
 *    receipt (`payroll_offset`, Dr 2130 Salaries Payable / Cr 1140 AR) with no
 *    money account, exactly as the ruling asks (never a mapping-row change).
 *
 * Additive, nullable or defaulted; MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('type');
            $table->unique('employee_id');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('is_staff_sale')->default(false)->after('is_opening');
            $table->index(['business_id', 'is_staff_sale']);
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->boolean('is_staff_sale')->default(false)->after('price_overridden');
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->string('kind', 32)->default('cash')->after('money_account_id');
            $table->unsignedBigInteger('payroll_run_id')->nullable()->after('kind');
            $table->index('payroll_run_id');
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('money_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropIndex(['payroll_run_id']);
            $table->dropColumn(['kind', 'payroll_run_id']);
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('is_staff_sale');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_staff_sale']);
            $table->dropColumn('is_staff_sale');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
