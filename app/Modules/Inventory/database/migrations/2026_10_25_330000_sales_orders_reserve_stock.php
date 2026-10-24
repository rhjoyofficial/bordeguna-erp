<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ A CONFIRMED SALES ORDER RESERVES STOCK (Joy, 24 Oct 2026, Q4 = A, R81,
 * B17): "If you have 500 bottles in the warehouse, and an SO is placed for
 * 400, the system tells your other salespeople that only 100 are 'Available
 * to Sell' so you don't accidentally double-sell the same stock."
 *
 * 1. `stock_reservations` (17 Oct 2026, staff purchases and owner withdrawals,
 *    one row per BATCH slice) also takes rows held at PRODUCT + WAREHOUSE
 *    level: `stock_batch_id` null, `product_id` + `warehouse_id` set. An order
 *    does not own a batch; the challan still takes batches FEFO, and only the
 *    total on the shelf is promised. Existing batch rows get their product and
 *    warehouse copied from the batch, so every reader can group the same way.
 *    `released_by` / `release_note` say who ended a hold by hand and why.
 *
 * 2. `stock_reservation_overrides`: every time a Manager or the Owner lets a
 *    document past a reservation, one row (who, why, how much):
 *      backorder      an order confirmed for more than is available
 *      take_reserved  a counter bill or direct challan took stock held for an order
 *      release        a hold ended by hand from the Reserved stock list
 *
 * 3. `sales.stock.override` for Owner and Manager (SalesPermissionsSeeder on a
 *    fresh install).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('stock_batch_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('released_by')->nullable()->after('released_at');
            $table->string('release_note', 500)->nullable()->after('released_by');
            $table->index(['product_id', 'warehouse_id', 'released_at'], 'stock_reservations_product_wh_live');
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_batch_id')->nullable()->change();
        });

        foreach (DB::table('stock_reservations')->whereNotNull('stock_batch_id')->whereNull('product_id')->get(['id', 'stock_batch_id']) as $row) {
            $batch = DB::table('stock_batches')->where('id', $row->stock_batch_id)->first(['product_id', 'warehouse_id']);

            if ($batch !== null) {
                DB::table('stock_reservations')->where('id', $row->id)->update(['product_id' => $batch->product_id, 'warehouse_id' => $batch->warehouse_id]);
            }
        }

        Schema::create('stock_reservation_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('kind', 20);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('available', 15, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 1000);
            $table->unsignedBigInteger('authorised_by');
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['product_id', 'created_at']);
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.stock.override')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'sales.stock.override',
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
        Schema::dropIfExists('stock_reservation_overrides');

        // ⚠ Order holds have no batch; a NOT NULL batch column cannot keep them.
        DB::table('stock_reservations')->whereNull('stock_batch_id')->delete();

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropIndex('stock_reservations_product_wh_live');
            $table->dropColumn(['product_id', 'warehouse_id', 'released_by', 'release_note']);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_batch_id')->nullable(false)->change();
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.stock.override')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
