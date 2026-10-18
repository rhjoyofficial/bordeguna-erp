<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing approvals (ruling P7, Joy's decision 18 Oct 2026).
 *
 * - Finance policy: the purchasing approval limit (Tk 200,000, AT OR ABOVE
 *   needs a second person), the approver roles (Owner, Manager), the price
 *   and discount tolerances, and one switch per exception, all ON.
 * - suppliers.bank_details_changed_at: when the bank or wallet a supplier is
 *   paid to last changed, so the next payment is approved.
 * - purchase_approvals: a bill, payment, advance or refund waiting for its
 *   second person. It holds what was typed; nothing is posted until approval.
 * - The `purchase_approval` workflow row per business, one stage for the
 *   Owner and Manager roles (EveryApprovalReachesSomebodyTest).
 *
 * ⚠ Data touched on a live database: new columns with defaults on
 * finance_policies (existing rows take the defaults), a nullable column on
 * suppliers (left NULL: no existing supplier counts as "bank changed"), and one
 * workflow + stage per business, inserted only where the key is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_policies', function (Blueprint $table) {
            // Paisa. 0 switches the amount rule off; the exceptions still apply.
            $table->unsignedBigInteger('purchasing_approval_at_or_above_minor')->default(20_000_000);
            // Role names; NULL means the default pair (Owner, Manager).
            $table->json('purchasing_approver_roles')->nullable();
            $table->unsignedSmallInteger('purchasing_price_tolerance_percent')->default(2);
            $table->unsignedSmallInteger('purchasing_unusual_discount_percent')->default(10);
            $table->boolean('purchasing_approve_non_po_bill')->default(true);
            $table->boolean('purchasing_approve_price_mismatch')->default(true);
            $table->boolean('purchasing_approve_unusual_discount')->default(true);
            $table->boolean('purchasing_approve_advance_over_application')->default(true);
            $table->boolean('purchasing_approve_new_supplier')->default(true);
            $table->boolean('purchasing_approve_bank_change')->default(true);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->timestamp('bank_details_changed_at')->nullable();
        });

        Schema::create('purchase_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // bill | payment | advance | refund
            $table->string('kind', 20);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('document_date');
            $table->string('reference')->nullable();
            $table->string('summary');
            // [{code, label}] - why it needs a second person.
            $table->json('reasons');
            // The typed document, replayed through the domain action on approval.
            $table->json('payload');
            // pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->nullableMorphs('document');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $workflowId = DB::table('approval_workflows')->where('business_id', $businessId)->where('key', 'purchase_approval')->value('id')
                ?? DB::table('approval_workflows')->insertGetId([
                    'business_id' => $businessId,
                    'key' => 'purchase_approval',
                    'name' => 'Purchasing approval (bills and payments)',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (! DB::table('approval_workflow_stages')->where('approval_workflow_id', $workflowId)->exists()) {
                DB::table('approval_workflow_stages')->insert([
                    'approval_workflow_id' => $workflowId,
                    'sequence' => 1,
                    'name' => 'Second person',
                    'allowed_roles' => json_encode(['Owner', 'Manager']),
                    'allowed_permissions' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $workflowIds = DB::table('approval_workflows')->where('key', 'purchase_approval')->pluck('id');
        DB::table('approval_workflow_stages')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();

        Schema::dropIfExists('purchase_approvals');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('bank_details_changed_at');
        });

        Schema::table('finance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'purchasing_approval_at_or_above_minor',
                'purchasing_approver_roles',
                'purchasing_price_tolerance_percent',
                'purchasing_unusual_discount_percent',
                'purchasing_approve_non_po_bill',
                'purchasing_approve_price_mismatch',
                'purchasing_approve_unusual_discount',
                'purchasing_approve_advance_over_application',
                'purchasing_approve_new_supplier',
                'purchasing_approve_bank_change',
            ]);
        });
    }
};
