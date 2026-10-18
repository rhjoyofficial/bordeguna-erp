<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ D-D (Joy, 18 Oct 2026): "staff ... paid it also instant or credit for
 * some days". A request may say `payment_mode = credit` with 7, 15 or 30
 * days; the handover leaves the invoice open on the employee's own customer
 * account, due that many days later, and it ages like any receivable.
 *
 *  - `credit_days`: what the employee chose (null unless on credit).
 *  - `credit_to_salary_at`: when an open credit purchase was moved to the
 *    salary deduction (the payroll then settles it like any deduction one).
 *
 * Additive and nullable; MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_purchase_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('credit_days')->nullable()->after('payment_mode');
            $table->timestamp('credit_to_salary_at')->nullable()->after('credit_days');
        });
    }

    public function down(): void
    {
        Schema::table('staff_purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['credit_days', 'credit_to_salary_at']);
        });
    }
};
