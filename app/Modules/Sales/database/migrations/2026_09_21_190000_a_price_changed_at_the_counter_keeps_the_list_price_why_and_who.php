<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠⚠ A PRICE CHANGED AT THE COUNTER LEAVES A TRAIL (Sales UX audit #13, settled
 * with ChatGPT for V1, 21 Sep 2026). Until today the counter sent a price with
 * every line, the server took whatever arrived, and anybody who could sell could
 * type any price or discount with nothing stored to say it had happened.
 *
 * Now the server prices every line itself. A price that differs from the list,
 * a line discount or a discount on the whole bill needs `sales.price.override`
 * and a reason, and the invoice keeps what the list said, why, and who.
 *
 * ⚠ All nullable or defaulted: every invoice written before today, and every
 * invoice raised from a challan or an order, simply has no override to record.
 * `list_price` NULL means "not overridden, the unit price IS the list price".
 *
 * ⚠ The permission is granted to Owner and Manager here rather than left to a
 * seeder run, because a deploy step that says "run the seeder" is a step that
 * gets skipped (the 4300 rule). No cap is set: the owner has not given one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            // What the resolver said this line should cost, stored only when
            // the counter changed the price or discounted the line.
            $table->decimal('list_price', 15, 4)->nullable()->after('unit_price');
            $table->boolean('price_overridden')->default(false)->after('list_price');
            $table->string('override_reason', 255)->nullable()->after('price_overridden');
            $table->foreignId('overridden_by')->nullable()->after('override_reason')->constrained('users')->restrictOnDelete();
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            // The same trail for a discount on the whole bill.
            $table->string('discount_reason', 255)->nullable()->after('invoice_discount');
            $table->foreignId('discount_given_by')->nullable()->after('discount_reason')->constrained('users')->restrictOnDelete();
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.price.override')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'sales.price.override',
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
        // ⚠ The foreign key first, then the column (MySQL error 1553 otherwise).
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_given_by');
            $table->dropColumn('discount_reason');
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['list_price', 'price_overridden', 'override_reason']);
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.price.override')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
