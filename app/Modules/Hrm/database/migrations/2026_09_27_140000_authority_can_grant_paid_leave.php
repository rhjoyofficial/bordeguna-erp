<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ 27 Sep 2026 (Joy): "if authority want can give pay leave although they do
 * not have pay leave." An approved leave request can be split into paid and
 * unpaid days (LEV-07) when the employee's balance cannot cover it. The
 * authority may decide to pay those unpaid days anyway - a company-financed
 * grant, not drawn from any quota - so the salary carries no Leave Without Pay
 * deduction for them.
 *
 * These three columns record that decision on the request: who made it, when,
 * and why. The reason is required at the UI, so a paid override is always
 * accountable, the same standard as every other money-affecting act in payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_override_by')->nullable()->after('applied_at');
            $table->timestamp('paid_override_at')->nullable()->after('paid_override_by');
            $table->string('paid_override_reason', 1000)->nullable()->after('paid_override_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['paid_override_by', 'paid_override_at', 'paid_override_reason']);
        });
    }
};
