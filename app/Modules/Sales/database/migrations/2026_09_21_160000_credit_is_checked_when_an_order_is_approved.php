<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠⚠ THE CREDIT LIMIT NOW BLOCKS, AND A DISTRIBUTOR PAYS FIRST (Joy, 21 Sep
 * 2026: "Block, Owner can override"). Until today the limit was shown beside
 * the order and enforced by nobody (C-SO-3).
 *
 * An Owner may still approve an order over the limit, but never silently: each
 * time is one row here, written once and never changed, with who, when, the
 * limit, what the customer owed before and after, and the reason (settled with
 * ChatGPT: an approval event, not a flag).
 *
 * The permission is granted to the Owner role here rather than left to a seeder
 * run, because a deploy step that says "run the seeder" is a step that gets
 * skipped (the 4300 rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            // 'credit_limit' or 'advance_only': which rule was set aside.
            $table->string('rule', 30);
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('owed_before', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->decimal('owed_after', 15, 2);
            $table->text('reason');
            $table->foreignId('overridden_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'customer_id']);
        });

        $permissionId = DB::table('permissions')->where('name', 'sales.credit.override')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'sales.credit.override',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->where('name', 'Owner')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_overrides');

        $permissionId = DB::table('permissions')->where('name', 'sales.credit.override')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
