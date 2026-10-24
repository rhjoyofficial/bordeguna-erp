<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ THE CREDIT LIMIT IS OVERRIDDEN ON THE SAME SCREEN (Joy, 25 Oct 2026,
 * Q11 = A, R87): an order, a counter sale on account or an invoice that takes a
 * customer over their limit is blocked, and a Manager or the Owner lets it
 * through there and then by typing THEIR OWN password and a reason. The
 * password re-authenticates that person and is never stored; the override is
 * one more row in credit_overrides (who, when, why) and a line in History.
 *
 * - `method`: 'approval' (the Approvals inbox, the path of 21 Sep 2026, which
 *   stays) or 'password' (the screen).
 * - `sales_invoice_id`: a counter sale or an invoice has no order to hang it on.
 * - `entered_by`: who was at the screen, when somebody else authorised it.
 *
 * `sales.credit.override` was the Owner's alone; Q11 names Manager or Owner,
 * so the Manager role gets it here (SalesPermissionsSeeder on a fresh install).
 *
 * ⚠ Blank credit limit = CASH ONLY from today for trade customers (shop,
 * dealer, corporate; CheckCustomerCredit). Nothing is written to customers:
 * `php artisan sales:credit-blank-limits` lists those who owe money today
 * with no limit, for Joy to decide one by one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_overrides', function (Blueprint $table) {
            $table->string('method', 20)->default('approval')->after('rule');
            $table->foreignId('sales_invoice_id')->nullable()->after('sales_order_id')->constrained()->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->after('overridden_by')->constrained('users')->nullOnDelete();
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.credit.override')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'sales.credit.override',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->whereIn('name', ['Owner', 'Manager'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('credit_overrides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_invoice_id');
            $table->dropConstrainedForeignId('entered_by');
            $table->dropColumn('method');
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.credit.override')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', DB::table('roles')->where('name', 'Manager')->pluck('id'))
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
