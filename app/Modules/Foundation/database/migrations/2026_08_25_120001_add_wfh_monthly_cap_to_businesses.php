<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-WFH-4: how many days a month somebody may work from home.
 *
 * ⚠ A business setting rather than a policy field, the same shape as
 * `payroll_cycle_start_day`, because Joy said "somewhere in 2 to 5 days" and
 * wanted to change it himself without a deploy. Seeded at 5, the top of the
 * range he named: a cap that starts too tight gets worked around on day one.
 *
 * ⚠⚠ Zero is a legitimate value and means work from home is not allowed here at
 * all. It is NOT the same as "no cap", which this column deliberately cannot
 * express: an uncapped allowance was never one of the choices Joy was offered,
 * and a nullable column would invite somebody to create one by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('wfh_monthly_cap')->default(5)->after('payroll_cycle_start_day');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('wfh_monthly_cap');
        });
    }
};
