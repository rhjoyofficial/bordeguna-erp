<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Accounting\Models\LoanTdsRule;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Database\Seeder;

/**
 * Who approves a loan, an amendment and a write-off or waiver: the Owner
 * (loans plan v2, phase 2, 14 Oct 2026).
 *
 * ⚠⚠ WITHOUT THESE ROWS EACH REQUEST WOULD REACH NOBODY, so the Every Approval
 * Reaches Somebody guard runs this seeder and checks every key. The migration
 * 2026_10_14_991300 plants the same rows in businesses that already exist.
 *
 * Also plants the TDS rule on interest paid on loans taken, SWITCHED OFF until
 * the accountant confirms it applies (LoanTdsRule).
 *
 * Idempotent: an existing workflow, its stages and an existing rule are left
 * untouched.
 */
class LoanWorkflowSeeder extends Seeder
{
    /** key => [workflow name, stage name] */
    public const WORKFLOWS = [
        'loan_contract' => ['Loan agreement', 'Owner approval'],
        'loan_amendment' => ['Loan amendment (restructure)', 'Owner approval'],
        'loan_write_off' => ['Loan write-off or interest waiver', 'Owner approval'],
    ];

    public function run(): void
    {
        foreach (Business::all() as $business) {
            foreach (self::WORKFLOWS as $key => [$name, $stage]) {
                $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'key' => $key],
                    ['name' => $name, 'is_active' => true],
                );

                if ($workflow->stages()->exists()) {
                    continue;
                }

                $workflow->stages()->create([
                    'sequence' => 1,
                    'name' => $stage,
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => [],
                ]);
            }

            if (! LoanTdsRule::withoutGlobalScope(BusinessScope::class)->where('business_id', $business->id)->exists()) {
                LoanTdsRule::withoutGlobalScope(BusinessScope::class)->create([
                    'business_id' => $business->id,
                    'name' => 'TDS on interest paid on a loan taken',
                    'applies_to' => LoanTdsRule::INTEREST_PAID_ON_LOANS_TAKEN,
                    'payee_kinds' => LoanTdsRule::DEFAULT_PAYEE_KINDS,
                    'rate' => '10',
                    'legal_basis' => 'ITA 2023 s.104 10%, to be confirmed by the accountant',
                    'effective_from' => '2023-07-01',
                    'is_enabled' => false,
                ]);
            }
        }
    }
}
