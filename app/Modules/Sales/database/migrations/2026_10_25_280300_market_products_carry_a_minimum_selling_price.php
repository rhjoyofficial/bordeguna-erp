<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ THE MINIMUM SELLING PRICE (Joy, 25 Oct 2026, Q16 = A, R12): fish, onion and
 * other market goods may carry an optional floor. Selling below it (a line's
 * price after its discount, or today's price confirmed below it) is allowed
 * only to a Manager or the Owner (`sales.prices.below_floor`) and only with a
 * reason, and each time is one row in price_floor_overrides (who, when, which
 * product, the price, the floor, why, on which document) plus a History line.
 *
 * Blank = no floor; nothing changes for a product until somebody types one.
 * Granted to Owner and Manager by name, the 4300 rule (no seeder step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('min_selling_price', 15, 4)->nullable();
        });

        Schema::create('price_floor_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // The document it happened on: an order, an invoice, or today's price.
            $table->string('document_type', 100);
            $table->unsignedBigInteger('document_id');
            $table->decimal('unit_price', 15, 4);
            $table->decimal('floor', 15, 4);
            $table->text('reason');
            $table->foreignId('allowed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'product_id']);
            $table->index(['document_type', 'document_id']);
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.prices.below_floor')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'sales.prices.below_floor',
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
        Schema::dropIfExists('price_floor_overrides');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_selling_price');
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.prices.below_floor')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
