<?php

use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Samples (Joy, 25 Oct 2026): "sometime we make sample product from raw
 * materials or take from product stock and the sample product we give many
 * people as to taste or gift purpose or marketing, etc. so we take no money,
 * but delivery charge also we bear in courier or hand delivery or instant
 * delivery etc."
 *
 *   sample_issues          SMP-2026-27-0001: one hand-out to one person (a
 *                          customer, a lead, a phone book contact, an employee)
 *                          for a purpose, delivered by hand, courier or rider,
 *                          the delivery charge ours. Approved (self-approved
 *                          under the finance policy limit) and then posted: the
 *                          goods leave at cost, Dr Samples and Marketing / Cr
 *                          Stock on Hand; the delivery charge Dr Sample
 *                          delivery / Cr the money account or Payable to
 *                          Couriers. Reversed through the corrections engine
 *                          (the Reversible columns), never edited or deleted.
 *   sample_issue_lines     what was given: a product, the batch chosen (or
 *                          FEFO when null), the quantity, and the cost once
 *                          posted.
 *   sample_issue_line_movements  the stock movements each line wrote (FEFO may
 *                          split one line over batches), the mirror of
 *                          delivery_line_movements.
 *   sample_makes           SMK-2026-27-0001: a small production of samples
 *                          from raw materials, consuming the batches picked at
 *                          their cost and creating one sample lot of the
 *                          output product at that cost.
 *   sample_make_inputs     the raw material batches a make consumed.
 *
 * Also: the `sales.samples.manage` permission (Owner, Manager, Staff), the
 * `sample_issue` approval workflow (Owner or Manager above the limit) and the
 * `sales.samples` / `sales.samples_delivery` mapping keys are LEFT to the heads
 * resolver (Sales\Support\SampleHeads), which creates the heads on first use
 * the way GiftExpenseAccount does.
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales.samples.manage' => ['Owner', 'Manager', 'Staff'],
    ];

    public function up(): void
    {
        Schema::create('sample_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('given_on');
            // Who got it: App\Modules\Sales\Models\Customer, Crm\Lead, Crm\Contact, Hrm\Employee.
            $table->string('recipient_type', 120)->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            // The name and phone as they were on the day, so the list reads
            // the same after a rename.
            $table->string('recipient_name');
            $table->string('recipient_phone', 40)->nullable();
            // tasting, gift, marketing, other
            $table->string('purpose', 20);
            $table->string('purpose_note', 255)->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // hand, courier, rider
            $table->string('delivery_method', 20)->default('hand');
            $table->decimal('delivery_charge', 15, 2)->default(0);
            // The money account the charge was paid from (hand or rider, or a
            // courier paid in cash), OR the courier account it is owed to.
            $table->foreignId('delivery_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('courier_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->string('delivery_district', 80)->nullable();
            $table->string('delivery_zone', 80)->nullable();
            // Ask the recipient how it was, on this day (a CRM follow-up).
            $table->date('feedback_on')->nullable();
            $table->foreignId('crm_task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();
            // waiting, posted, rejected
            $table->string('status', 20)->default('waiting');
            $table->unsignedBigInteger('cost_total_paisa')->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('given_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'sample_issues_number_unique');
            $table->index(['business_id', 'given_on']);
            $table->index(['recipient_type', 'recipient_id']);
            $table->index('purpose');
        });

        Schema::create('sample_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // The batch the person chose; null = FEFO at posting.
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            // Set at posting, from the batches that left.
            $table->unsignedBigInteger('cost_paisa')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('sample_issue_line_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_issue_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique('stock_movement_id', 'sample_issue_line_movements_movement_unique');
        });

        Schema::create('sample_makes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->date('made_on');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            // The sample lot it created.
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cost_total_paisa')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'sample_makes_number_unique');
            $table->index(['business_id', 'made_on']);
        });

        Schema::create('sample_make_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_make_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->unsignedBigInteger('cost_paisa')->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
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

        // The `sample_issue` workflow (Owner or Manager), for every business.
        (new ApprovalWorkflowSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_make_inputs');
        Schema::dropIfExists('sample_makes');
        Schema::dropIfExists('sample_issue_line_movements');
        Schema::dropIfExists('sample_issue_lines');
        Schema::dropIfExists('sample_issues');

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
