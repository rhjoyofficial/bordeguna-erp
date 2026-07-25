<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * The seven leave types of LEV-01, with Correction C6c's defaults applied.
 *
 * Only Earned/Annual carries forward and encashes. LEV-03's literal answer
 * would have applied both to every type — carrying forward and paying out
 * maternity and sick balances — which C6c corrected.
 *
 * Quotas are left at zero except where the questionnaire is explicit, because
 * it never states them: HR sets the numbers, and a wrong seeded quota would be
 * an invented business rule rather than a helpful default.
 */
class LeaveTypeSeeder extends Seeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'casual', 'name' => 'Casual Leave',
                'annual_quota' => 10, 'is_paid' => true, 'deducts_balance' => true,
                'allows_half_day' => true, 'can_carry_forward' => false, 'can_encash' => false,
            ],
            [
                'code' => 'sick', 'name' => 'Sick / Medical Leave',
                'annual_quota' => 14, 'is_paid' => true, 'deducts_balance' => true,
                'allows_half_day' => true, 'can_carry_forward' => false, 'can_encash' => false,
            ],
            [
                // The only type C6c allows to accumulate and be paid out.
                'code' => 'earned', 'name' => 'Earned / Annual Leave',
                'annual_quota' => 20, 'is_paid' => true, 'deducts_balance' => true,
                'allows_half_day' => true, 'can_carry_forward' => true, 'can_encash' => true,
                // Null means twice the annual quota (C6b's configurable default).
                'carry_forward_cap' => null,
            ],
            [
                'code' => 'maternity', 'name' => 'Maternity Leave',
                'annual_quota' => 0, 'is_paid' => true, 'deducts_balance' => false,
                'allows_half_day' => false, 'can_carry_forward' => false, 'can_encash' => false,
            ],
            [
                'code' => 'paternity', 'name' => 'Paternity Leave',
                'annual_quota' => 0, 'is_paid' => true, 'deducts_balance' => false,
                'allows_half_day' => false, 'can_carry_forward' => false, 'can_encash' => false,
            ],
            [
                // LEV-07's destination: recorded, but draws on no balance.
                'code' => 'unpaid', 'name' => 'Unpaid Leave',
                'annual_quota' => 0, 'is_paid' => false, 'deducts_balance' => false,
                'allows_half_day' => true, 'can_carry_forward' => false, 'can_encash' => false,
            ],
            [
                'code' => 'special', 'name' => 'Special Leave',
                'annual_quota' => 0, 'is_paid' => true, 'deducts_balance' => false,
                'allows_half_day' => true, 'can_carry_forward' => false, 'can_encash' => false,
            ],
        ];
    }

    public function run(): void
    {
        foreach (Business::all() as $business) {
            foreach (self::definitions() as $definition) {
                LeaveType::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'code' => $definition['code']],
                    [...$definition, 'business_id' => $business->id, 'is_active' => true],
                );
            }
        }
    }
}
