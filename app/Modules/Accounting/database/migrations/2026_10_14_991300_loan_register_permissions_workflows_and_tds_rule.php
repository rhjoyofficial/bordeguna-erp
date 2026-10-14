<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the Loan Register needs in every business that already exists, so a
 * deploy needs `migrate` and nothing else (loans plan v2, phase 2, 14 Oct 2026):
 *
 *  1. PERMISSIONS
 *       accounting.loans.view    the register, a loan's page, the reports:
 *                                Owner, Finance and Manager
 *       accounting.loans.manage  draft a loan, record its money, accrue
 *                                interest, ask for an amendment or a write-off:
 *                                Owner and Finance
 *
 *  2. ⚠⚠ THE APPROVAL WORKFLOW ROWS. Without a row an approvable type reaches
 *     NOBODY (the expense-claim lesson, EveryApprovalReachesSomebodyTest): a
 *     loan, an amendment and a write-off or waiver each go to the Owner.
 *     LoanWorkflowSeeder plants the same rows on a fresh install.
 *
 *  3. THE TDS RULE on interest paid on loans taken, seeded SWITCHED OFF: ITA
 *     2023 s.104 (10%) applies only where Bor de Guna is a "specified person"
 *     and the lender is not a bank or finance company, which the accountant has
 *     to confirm before anything is withheld (ChatGPT confirmation, 14 Oct 2026).
 *
 * Idempotent: existing rows are left exactly as they are.
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounting.loans.view' => ['Owner', 'Finance', 'Manager'],
        'accounting.loans.manage' => ['Owner', 'Finance'],
    ];

    /** key => [workflow name, stage name] */
    private const WORKFLOWS = [
        'loan_contract' => ['Loan agreement', 'Owner approval'],
        'loan_amendment' => ['Loan amendment (restructure)', 'Owner approval'],
        'loan_write_off' => ['Loan write-off or interest waiver', 'Owner approval'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table('permissions')->where('name', (string) $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('roles')->whereIn('name', array_map('strval', $roles))->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            foreach (self::WORKFLOWS as $key => [$name, $stage]) {
                $workflowId = DB::table('approval_workflows')->where('business_id', $businessId)->where('key', $key)->value('id')
                    ?? DB::table('approval_workflows')->insertGetId([
                        'business_id' => $businessId,
                        'key' => $key,
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                if (! DB::table('approval_workflow_stages')->where('approval_workflow_id', $workflowId)->exists()) {
                    DB::table('approval_workflow_stages')->insert([
                        'approval_workflow_id' => $workflowId,
                        'sequence' => 1,
                        'name' => $stage,
                        'allowed_roles' => json_encode(['Owner']),
                        'allowed_permissions' => json_encode([]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (! DB::table('loan_tds_rules')->where('business_id', $businessId)->exists()) {
                DB::table('loan_tds_rules')->insert([
                    'business_id' => $businessId,
                    'name' => 'TDS on interest paid on a loan taken',
                    'applies_to' => 'interest_paid_on_loans_taken',
                    'payee_kinds' => json_encode(['company', 'individual', 'government', 'other']),
                    'rate' => '10.0000',
                    'legal_basis' => 'ITA 2023 s.104 10%, to be confirmed by the accountant',
                    'effective_from' => '2023-07-01',
                    'is_enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $workflowIds = DB::table('approval_workflows')->whereIn('key', array_keys(self::WORKFLOWS))->pluck('id');

        // ⚠ A workflow somebody has already been asked to approve stays.
        $used = DB::table('approval_requests')->whereIn('workflow_id', $workflowIds)->distinct()->pluck('workflow_id');
        $unused = $workflowIds->diff($used)->values();

        DB::table('approval_workflow_stages')->whereIn('approval_workflow_id', $unused)->delete();
        DB::table('approval_workflows')->whereIn('id', $unused)->delete();

        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', (string) $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
