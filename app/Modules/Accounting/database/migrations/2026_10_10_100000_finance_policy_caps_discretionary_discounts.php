<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 26 Sep 2026: "Owner unlimited. Manager / Person in Charge: max 3% of
 * the MERCHANDISE subtotal per bill. Staff with the granted permission: max 1%
 * of merchandise subtotal OR Tk 300 per bill, whichever is LOWER. Cap excludes
 * delivery charge and VAT."
 *
 * The numbers live in the Finance policy so the Owner can change them. Person
 * in Charge is a permission given to named people, not a role, so it is
 * created here and handed to nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_policies', function (Blueprint $table) {
            $table->unsignedTinyInteger('manager_discount_cap_percent')->default(3)->after('staff_purchase_cap_percent');
            $table->unsignedTinyInteger('staff_discount_cap_percent')->default(1)->after('manager_discount_cap_percent');
            $table->unsignedBigInteger('staff_discount_cap_minor')->default(30_000)->after('staff_discount_cap_percent');
        });

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'sales.discount.in_charge',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('finance_policies', function (Blueprint $table) {
            $table->dropColumn(['manager_discount_cap_percent', 'staff_discount_cap_percent', 'staff_discount_cap_minor']);
        });
    }
};
