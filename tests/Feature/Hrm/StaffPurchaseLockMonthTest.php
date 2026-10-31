<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\StaffPurchaseLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The month lock row carries the business it was taken for. The employee has no
 * business_id of its own, so the caller passes it; a null here was swallowed by
 * insertOrIgnore and left nothing to lock.
 */
it('writes the month counter row with the business it is locked for', function () {
    $business = Business::create(['name' => 'Lock Co', 'slug' => 'lock-co']);
    app(BusinessContext::class)->set($business->id);

    $employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);

    DB::transaction(fn () => StaffPurchaseLimits::lockMonth($employee, $business->id, Carbon::parse('2026-10-25')));

    $row = DB::table('staff_purchase_month_counters')->where('employee_id', $employee->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->business_id)->toBe($business->id)
        ->and([(int) $row->year, (int) $row->month])->toBe([2026, 11]);
});
