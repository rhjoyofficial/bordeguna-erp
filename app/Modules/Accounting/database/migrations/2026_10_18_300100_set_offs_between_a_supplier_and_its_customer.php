<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Set-offs (decision D-F, 18 Oct 2026): one firm is our supplier AND our
 * customer, and an approved document clears an open supplier bill against an
 * open customer invoice of that same firm.
 *
 *   set_offs        SET-2026-27-0001: the party (supplier + linked customer),
 *                   the date, the amount, the reason, its status
 *                   (pending, posted, rejected, reversed) and, once posted,
 *                   its journal and its party adjustment
 *   set_off_lines   what it takes off each bill and each invoice
 *
 * Posting (on the last approval): Dr 2110 Accounts Payable (party = supplier)
 * / Cr 1140 Accounts Receivable (party = customer), with the allocations kept
 * as a `set_off` party adjustment, so every reader that already knows
 * adjustments (the bill's and the invoice's outstanding, both statements, the
 * ageing, the subledger reconciliation) reads a set-off without learning a new
 * table.
 *
 * ⚠ The lines are the PROPOSAL while it waits: nothing touches an invoice, a
 * bill or the ledger until it is approved. A reversal (Correct > Reverse, the
 * corrections process) reverses the journal and removes the adjustment, so
 * both documents are open again; the set-off itself stays, marked Reversed.
 *
 * Also, idempotently, for businesses that already exist:
 *  - `accounting.set_off.manage` (raise a set-off): Owner and Finance;
 *  - the `voucher` approval workflow the set-off goes through (the Owner, or
 *    anyone who may post vouchers), the same row VoucherWorkflowSeeder plants.
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounting.set_off.manage' => ['Owner', 'Finance'],
    ];

    public function up(): void
    {
        Schema::create('set_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('set_off_on');
            $table->decimal('amount', 15, 4);
            $table->text('reason');
            // pending, posted, rejected, reversed (SetOff::STATUSES).
            $table->string('status', 20)->default('pending');
            $table->foreignId('party_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('decision_note')->nullable();
            // The Reversible columns, written by the corrections process only.
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'set_offs_number_unique');
            $table->index(['business_id', 'set_off_on']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('set_off_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_off_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index('sales_invoice_id');
            $table->index('supplier_bill_id');
        });

        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ⚠⚠ Without the workflow row a set-off sent for approval reaches
        // NOBODY (EveryApprovalReachesSomebodyTest). Left exactly as it is
        // where it already exists.
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $workflowId = DB::table('approval_workflows')->where('business_id', $businessId)->where('key', 'voucher')->value('id')
                ?? DB::table('approval_workflows')->insertGetId([
                    'business_id' => $businessId,
                    'key' => 'voucher',
                    'name' => 'Voucher above the approval limit',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (! DB::table('approval_workflow_stages')->where('approval_workflow_id', $workflowId)->exists()) {
                DB::table('approval_workflow_stages')->insert([
                    'approval_workflow_id' => $workflowId,
                    'sequence' => 1,
                    'name' => 'Second approval',
                    'allowed_roles' => json_encode(['Owner']),
                    'allowed_permissions' => json_encode(['accounting.voucher.post']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('set_off_lines');
        Schema::dropIfExists('set_offs');

        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
