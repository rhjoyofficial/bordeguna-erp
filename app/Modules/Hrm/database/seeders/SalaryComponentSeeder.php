<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\SalaryComponent;
use Illuminate\Database\Seeder;

/**
 * The earning and deduction components the business already uses, taken
 * verbatim from SAL-02 and SAL-03.
 *
 * Every component seeds with a **fixed** basis and no amount. That is
 * deliberate: SAL-02/03 name the components but never state their values or
 * formulas, so seeding a percentage would invent a business rule. HR sets the
 * figures per employee.
 *
 * Income Tax and Provident Fund are flagged `is_statutory` and are likewise
 * plain manual amounts — **GOV-06 (statutory tax/PF automation scope) is still
 * unresolved**, and the flag exists so that when it is answered, the automated
 * rule has somewhere obvious to attach without a migration.
 */
class SalaryComponentSeeder extends Seeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            // --- Earnings (SAL-02) ---
            ['code' => 'basic', 'name' => 'Basic', 'type' => 'earning', 'is_basic' => true, 'sequence' => 10,
                'description' => 'The base figure other components may be a percentage of.'],
            ['code' => 'house_rent', 'name' => 'House Rent', 'type' => 'earning', 'sequence' => 20],
            ['code' => 'medical', 'name' => 'Medical Allowance', 'type' => 'earning', 'sequence' => 30],
            ['code' => 'conveyance', 'name' => 'Conveyance', 'type' => 'earning', 'sequence' => 40],

            // --- Deductions (SAL-03) ---
            ['code' => 'income_tax', 'name' => 'Income Tax', 'type' => 'deduction', 'sequence' => 10,
                'is_statutory' => true,
                'description' => 'Entered manually until GOV-06 defines the statutory calculation.'],
            ['code' => 'provident_fund', 'name' => 'Provident Fund', 'type' => 'deduction', 'sequence' => 20,
                'is_statutory' => true,
                'description' => 'Entered manually until GOV-06 defines the statutory calculation.'],
            ['code' => 'loan_repayment', 'name' => 'Loan Repayment', 'type' => 'deduction', 'sequence' => 30,
                'description' => 'Populated by payroll from an approved loan (ADJ-07, Phase 3e).'],
            ['code' => 'advance_recovery', 'name' => 'Salary Advance Recovery', 'type' => 'deduction', 'sequence' => 40,
                'description' => 'Populated by payroll from an approved advance (ADJ-07, Phase 3e).'],
            ['code' => 'late_early', 'name' => 'Late / Early Deduction', 'type' => 'deduction', 'sequence' => 50,
                'description' => 'Priced by payroll from the attendance consequence (POL-03 / PAY-07).'],
            ['code' => 'lwp', 'name' => 'Leave Without Pay', 'type' => 'deduction', 'sequence' => 60,
                'description' => 'Priced by payroll from unpaid leave days (PAY-04).'],
            ['code' => 'absent', 'name' => 'Absent Deduction', 'type' => 'deduction', 'sequence' => 70,
                'description' => 'Priced by payroll from absent days (PAY-03).'],
            ['code' => 'festival_loan', 'name' => 'Festival Loan Recovery', 'type' => 'deduction', 'sequence' => 80],
            ['code' => 'other', 'name' => 'Other Deductions', 'type' => 'deduction', 'sequence' => 90],
        ];
    }

    public function run(): void
    {
        foreach (Business::all() as $business) {
            foreach (self::definitions() as $definition) {
                SalaryComponent::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'code' => $definition['code']],
                    [
                        'business_id' => $business->id,
                        'name' => $definition['name'],
                        'type' => $definition['type'],
                        'is_basic' => $definition['is_basic'] ?? false,
                        'calculation_basis' => 'fixed',
                        'sequence' => $definition['sequence'],
                        'is_statutory' => $definition['is_statutory'] ?? false,
                        'description' => $definition['description'] ?? null,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
