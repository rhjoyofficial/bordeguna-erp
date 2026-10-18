<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Supplier advances (supplier finance plan A2, ruling P2, 18 Oct 2026): money
 * paid to a supplier before the bill, used against bills later, or refunded.
 *
 *   supplier_advances               SADV-2026-27-0001: Dr 1160 Advances to
 *                                   Suppliers (party = supplier) / Cr the money
 *                                   account (and 2180 TDS when held back). Kind
 *                                   `payment` (Purchases > Payments, "Advance"),
 *                                   `overpayment` (the excess of a supplier
 *                                   payment, posted on that payment's journal)
 *                                   or `opening` (Dr 1160 / Cr 3900 at cutover).
 *   supplier_advance_applications   SAAP-2026-27-0001: one advance (or the
 *                                   supplier's on-account 1160 balance, when
 *                                   `supplier_advance_id` is null) used against
 *                                   one bill: Dr 2110 AP / Cr 1160, both with
 *                                   the supplier. The bill is reduced through
 *                                   an `advance_applied` party adjustment, so
 *                                   every reader that already knows
 *                                   adjustments (outstanding, ageing, the
 *                                   statements, the AP reconciliation, the
 *                                   cockpit) reads an application unchanged.
 *   supplier_advance_refunds        SARF-2026-27-0001: the supplier gives the
 *                                   money back: Dr the money account / Cr 1160.
 *
 * ⚠ All three are corrected by reversal with a reason (the Reversible
 * columns), never edited or deleted, and only in dependency order: an advance
 * with a live application or refund is refused until those are reversed.
 *
 * Also, idempotently: `purchasing.advance.record` for every role that may
 * record a supplier payment today (Owner and Manager as seeded).
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            // payment, overpayment, opening
            $table->string('kind', 20)->default('payment');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            // The payment whose excess this is (kind overpayment).
            $table->foreignId('supplier_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('paid_on');
            // Gross: what the supplier is credited with, the 1160 debit.
            $table->decimal('amount', 15, 4);
            $table->foreignId('tds_rate_id')->nullable()->constrained('withholding_rates')->restrictOnDelete();
            $table->decimal('tds_amount', 15, 4)->default(0);
            $table->foreignId('vds_rate_id')->nullable()->constrained('withholding_rates')->restrictOnDelete();
            $table->decimal('vds_amount', 15, 4)->default(0);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'supplier_advances_number_unique');
            $table->index(['supplier_id', 'paid_on']);
            $table->index('purchase_order_id');
        });

        Schema::create('supplier_advance_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // Null: the supplier's on-account 1160 balance from vouchers and journals.
            $table->foreignId('supplier_advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->date('applied_on');
            $table->decimal('amount', 15, 4);
            // The TDS already held back on this part of the advance (information:
            // the bill's later payment is only on what is left, so it is never
            // taxed twice).
            $table->decimal('tds_covered', 15, 4)->default(0);
            // The `advance_applied` adjustment that reduces the bill; removed on reversal.
            $table->foreignId('party_adjustment_id')->nullable()->constrained()->nullOnDelete();
            // For an on-account application: the vouchers or journals it drew on.
            $table->string('source_note')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'supplier_advance_applications_number_unique');
            $table->index('supplier_advance_id');
            $table->index('supplier_bill_id');
            $table->index(['supplier_id', 'applied_on']);
        });

        Schema::create('supplier_advance_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // Null: refunded out of the on-account 1160 balance.
            $table->foreignId('supplier_advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->date('refunded_on');
            $table->decimal('amount', 15, 4);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'supplier_advance_refunds_number_unique');
            $table->index('supplier_advance_id');
            $table->index(['supplier_id', 'refunded_on']);
        });

        $permissionId = DB::table('permissions')->where('name', 'purchasing.advance.record')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'purchasing.advance.record',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // ⭐ Joy, 18 Oct 2026: Owner, Manager and Finance record advances
        // (not every role that may pay a supplier, e.g. an external accountant).
        foreach (DB::table('roles')->whereIn('name', ['Owner', 'Manager', 'Finance'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_refunds');
        Schema::dropIfExists('supplier_advance_applications');
        Schema::dropIfExists('supplier_advances');

        $permissionId = DB::table('permissions')->where('name', 'purchasing.advance.record')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
