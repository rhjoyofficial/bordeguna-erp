<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Buy and receive on one screen (supplier finance wave C, ruling P4,
 * 18 Oct 2026).
 *
 *   quick_purchases   one row per submission of Purchases > Buy and receive.
 *                     It is NOT an accounting document: it posts nothing and
 *                     owns no money. It remembers the client's request key (so
 *                     a double click or a retry returns the same set of
 *                     documents instead of a second set) and links the
 *                     documents the existing actions created: the purchase
 *                     order, the goods receipt, the landed cost, the supplier
 *                     bill and the payment, each with its own number, status
 *                     and audit trail.
 *
 * Also, idempotently: the permission `purchasing.quick.purchase`, granted to
 * every role named Owner, Manager or Finance in every business (Joy, 18 Oct
 * 2026). It opens the screen only: each step still needs its own permission
 * (raise and approve the order, receive, bill, pay, landed cost).
 *
 * ⚠ Data touched on a live database: `permissions` (one row, only when absent)
 * and `role_has_permissions` (insertOrIgnore). No account, no journal, no
 * existing document.
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠ Guarded so the grant below can run again on its own (idempotent).
        if (! Schema::hasTable('quick_purchases')) {
            $this->createTable();
        }

        $permissionId = DB::table('permissions')->where('name', 'purchasing.quick.purchase')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'purchasing.quick.purchase',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->whereIn('name', ['Owner', 'Manager', 'Finance'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createTable(): void
    {
        Schema::create('quick_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The browser's key for ONE submission (a UUID made when the form opened).
            $table->string('request_key', 64);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('purchased_on');
            // completed, awaiting_order_approval, awaiting_bill_approval,
            // awaiting_payment_approval, awaiting_approval (the whole purchase).
            $table->string('status', 30);
            // What waits for whom, in a sentence, when the status is awaiting_*.
            $table->text('held_reason')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('landed_cost_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->nullable()->constrained()->restrictOnDelete();
            // What was typed, in paisa, for the result screen (the documents hold the truth).
            $table->bigInteger('goods_paisa')->default(0);
            $table->bigInteger('extra_costs_paisa')->default(0);
            $table->bigInteger('paid_paisa')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // ⚠⚠ The idempotency guarantee: the second insert of the same key
            // waits for the first transaction and then fails, never a second set.
            $table->unique(['business_id', 'request_key'], 'quick_purchases_request_key_unique');
            $table->index(['supplier_id', 'purchased_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_purchases');

        $permissionId = DB::table('permissions')->where('name', 'purchasing.quick.purchase')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
