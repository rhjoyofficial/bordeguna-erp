<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\BreakPolicy;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Enums\ScheduleType;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\ScheduleAssignment;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Bor De Guna's attendance configuration: the lateness policy, the shifts, and
 * which team works which one.
 *
 * ⚠ **This provisions; it does not administer.** Everything it writes is
 * ordinary data with a screen behind it — shifts, holiday calendars and
 * schedule assignments are all managed through the UI. Adding an outlet,
 * changing a start time or moving a team onto a different shift is a few
 * clicks, not a release. The seeder exists so a new business opens on working
 * configuration rather than an empty screen, and so this exact setup can be
 * rebuilt from scratch.
 *
 * **Scalability, since Bor De Guna expects more offices, warehouses and
 * outlets.** Three properties carry it, and none of them needs code to grow:
 *
 *  1. **The lateness policy is expressed in minutes after shift start, never in
 *     clock times.** One policy therefore governs every shift the company will
 *     ever have — a 06:00 outlet shift inherits the same ladder as the 09:00
 *     office without a second policy or a line of code.
 *  2. **Schedules are assigned by SCOPE with precedence** (business < office <
 *     department < section < employee, most specific winning). A new outlet
 *     needs one department-scoped row; a single exception needs one
 *     employee-scoped row. Nobody is ever assigned individually just to be
 *     covered, and a new hire inherits their team's hours with no backfill.
 *  3. **Holiday calendars are per business, versioned by year, and edited by
 *     hand.** Management can observe or skip any holiday without a release,
 *     and a team that trades on public holidays can be given its own calendar.
 *
 * ⚠ **The one gap in "no code changes": the attendance policy has no admin
 * screen.** The config below is data, versioned and effective-dated (POL-12) —
 * but today it is edited by a seeder, which is a release. A policy editor is
 * the natural next piece of configuration work, and it is worth having before
 * the rules change rather than after.
 */
class BorDeGunaAttendanceSeeder extends Seeder
{
    private const SLUG = 'bor-de-guna';

    /**
     * The shifts, as data.
     *
     * ⚠ `grace_minutes` is deliberately 0 on both. **Lateness grace lives in
     * the policy's first band, not on the shift** — the engine measures
     * lateness purely as minutes after `start_time`, and the ladder's
     * `from_minutes`/`to_minutes` decide what that costs. Putting a grace here
     * as well would forgive the first minutes twice.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SHIFTS = [
        'Office' => [
            'start_time' => '09:00',
            'end_time' => '18:00',
            'note' => 'Head office hours. Staff are asked to arrive by 08:45; the paid day begins at 09:00.',
        ],
        'Retail' => [
            'start_time' => '08:30',
            'end_time' => '18:00',
            'note' => 'Retail hours. Staff are asked to arrive by 08:15; the paid day begins at 08:30.',
        ],
    ];

    /** Which team works which shift. A new team is one line here, or one click in the UI. */
    private const DEPARTMENT_SHIFTS = [
        'Main Team' => 'Office',
        'Office Assistant Team' => 'Office',
        'Retail Sales Team' => 'Retail',
    ];

    /**
     * Employees who work to something other than their team's schedule.
     *
     * ⚠ Bor De Guna's two Retail Sales Officers give fingerprint at the office
     * each morning and finish from the field. They sit in the Retail Sales Team
     * with the Assistant Sales Manager, who *does* keep normal hours — so the
     * exception is per person, not per team, and `ScheduleScope::Employee`
     * outranks the department row without disturbing it. This is exactly the
     * "employee exceptions" case the scope precedence was built for.
     *
     * Paired with Correction C13: a field-duty day is judged on turning up
     * rather than on closing a punch pair.
     *
     * @var array<int, string>
     */
    private const FIELD_DUTY_EMPLOYEES = [
        'BDG-000020',
        'BDG-000021',
    ];

    /**
     * Bor De Guna's lateness ladder, confirmed by Joy 2026-07-28.
     *
     * **Two independent monthly allowances plus an unpaid cliff:**
     *
     * | Late by | Free per month | Then |
     * |---|---|---|
     * | 1–15 min (09:01–09:15) | 3 days | 1 day's salary each |
     * | 16–180 min (09:16–12:00) | 2 days | 1 day's salary each |
     * | over 180 min (after 12:00) | none | 1 day's salary, every time |
     *
     * The third band is Joy's rule that somebody arriving after midday "will
     * work but not get paid" — so it deducts from the first occurrence, with no
     * allowance. **Forgiveness is deliberately not modelled as a policy
     * exception:** where management waives it, that is a separate, approved,
     * auditable money decision (a payroll adjustment), not an edit to what the
     * attendance record says happened. Corrections in this system are events,
     * never rewrites.
     *
     * ⚠⚠ **The bands are minutes after the shift start, not clock times.** That
     * is what lets one policy serve every shift — but it means the boundaries
     * shift with the shift. For the 09:00 office shift band 2 ends at 12:00
     * exactly, as described. For the 08:30 Retail shift the same 180 minutes
     * ends at **11:30**. Only the Assistant Sales Manager is affected (the two
     * officers are field duty and not judged on punctuality), and only in the
     * 11:30–12:00 window. **Worth confirming with Joy whether the rule is
     * "three hours late" or "after 12:00 by the clock"** — the former is what is
     * seeded, because it is what the engine expresses natively.
     *
     * @return array<string, mixed>
     */
    public static function policyConfig(): array
    {
        return [
            'grace_windows' => [
                [
                    'key' => 'slightly_late',
                    'label' => 'Up to 15 minutes late',
                    'from_minutes' => 0,   // exclusive: 09:00:01 counts
                    'to_minutes' => 15,    // inclusive: 09:15:00 is still this band
                    'tiers' => [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        // Three free days; the fourth costs a day's salary.
                        ['from_occurrence' => 4, 'consequence' => 'deduction'],
                    ],
                ],
                [
                    'key' => 'late_arrival',
                    'label' => '15 minutes to 3 hours late',
                    'from_minutes' => 15,  // exclusive: 09:15:01 starts this band
                    'to_minutes' => 180,   // 12:00 for a 09:00 shift
                    'tiers' => [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        // Two free days; the third costs a day's salary.
                        ['from_occurrence' => 3, 'consequence' => 'deduction'],
                    ],
                ],
                [
                    'key' => 'after_midday',
                    'label' => 'More than 3 hours late',
                    'from_minutes' => 180,
                    // Open-ended: no upper bound, so the ladder cannot run out.
                    'to_minutes' => null,
                    'tiers' => [
                        // No allowance. "Will work but not get paid."
                        ['from_occurrence' => 1, 'consequence' => 'deduction'],
                    ],
                ],
            ],
            'early_leave_grace_minutes' => 15,
            'half_day_maximum_hours' => 4,
            'full_day_minimum_hours' => 8,
            'half_day_arrival_after_minutes' => null,
            'count_holiday_work_without_approval' => false,
        ];
    }

    public function run(): void
    {
        $business = Business::where('slug', self::SLUG)->first();

        if ($business === null) {
            return;
        }

        app(BusinessContext::class)->set($business->id);

        $this->seedPolicy($business);
        $this->seedShifts();
        $this->seedSchedules($business);
    }

    /**
     * ⚠ **Provisioning, and it refuses to amend.**
     *
     * `AttendancePolicySeeder` already gave every business POL-03's *worked
     * example* as a starting point. Bor De Guna's real rule replaces it — but
     * only while the policy is still untouched by operations. Once a single day
     * has been judged under it, POL-12's versioning applies: a rule change
     * becomes a **new version with its own effective date**, because a day must
     * always be judged by the policy in force on that date. Overwriting then
     * would silently restate history.
     */
    private function seedPolicy(Business $business): void
    {
        $policy = AttendancePolicy::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->orderByDesc('version')
            ->first();

        $hasJudgedDays = DailyAttendance::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->exists();

        if ($policy !== null && $hasJudgedDays) {
            // Deliberately silent and deliberately inert: changing the rules now
            // is a versioning decision for a human, not a seeder's to make.
            return;
        }

        if ($policy === null) {
            AttendancePolicy::create([
                'business_id' => $business->id,
                'name' => 'Bor De Guna attendance policy',
                'version' => 1,
                'config' => self::policyConfig(),
                'effective_from' => $this->effectiveFrom(),
            ]);

            return;
        }

        $policy->update([
            'name' => 'Bor De Guna attendance policy',
            'config' => self::policyConfig(),
        ]);
    }

    private function seedShifts(): void
    {
        foreach (self::SHIFTS as $name => $shift) {
            Shift::firstOrCreate(
                ['name' => $name],
                [
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                    // See the SHIFTS docblock: grace belongs to the policy band.
                    'grace_minutes' => 0,
                    // Lunch is working time (confirmed 2026-07-28), so the paid
                    // policy deducts nothing. The 90 minutes is recorded because
                    // it is what actually happens, not because it is subtracted.
                    'break_minutes' => 90,
                    'break_policy' => BreakPolicy::Paid,
                    'is_overnight' => false,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedSchedules(Business $business): void
    {
        $calendar = HolidayCalendar::where('is_default', true)->first()
            ?? HolidayCalendar::first();

        foreach (self::DEPARTMENT_SHIFTS as $departmentName => $shiftName) {
            $department = Department::where('name', $departmentName)->first();
            $shift = Shift::where('name', $shiftName)->first();

            if ($department === null || $shift === null) {
                continue;
            }

            $this->assign($business, ScheduleScope::Department, $department->id, ScheduleType::Fixed, $shift->id, $calendar?->id);
        }

        // Employee-scope exceptions outrank the department row above (C13).
        foreach (self::FIELD_DUTY_EMPLOYEES as $code) {
            $employee = Employee::withoutGlobalScopes()->where('employee_code', $code)->first();

            if ($employee === null) {
                // Employees are migrated later; re-running this seeder after
                // migration wires the exceptions up. Skipping is correct, not a
                // failure — the seeder is idempotent by design.
                continue;
            }

            $this->assign(
                $business,
                ScheduleScope::Employee,
                $employee->id,
                ScheduleType::FieldDuty,
                // Field duty carries no shift: they are judged on turning up
                // and on hours worked, never on punctuality (SCH-01/POL-10).
                null,
                $calendar?->id,
            );
        }
    }

    private function assign(
        Business $business,
        ScheduleScope $scope,
        int $scopeId,
        ScheduleType $type,
        ?int $shiftId,
        ?int $calendarId,
    ): void {
        // ⚠⚠ **THE DATE-CAST TRAP, FIFTH APPEARANCE — this time inside
        // `firstOrCreate`.** `effective_from` is a `date`-cast column, so it
        // stores `2026-07-28 00:00:00`, while the lookup value is the bare
        // `2026-07-28`. The two never match, so `firstOrCreate` found nothing
        // and created a duplicate on every run — three department schedules
        // became six, and a second run would have made nine. Silent, and the
        // schedule resolver would then have had two equally-specific rows to
        // choose between.
        //
        // `whereDate()` on the lookup and an explicit create is the fix, and it
        // is why this is not written as `firstOrCreate`.
        $exists = ScheduleAssignment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('scope_type', $scope->value)
            ->where('scope_id', $scopeId)
            ->whereDate('effective_from', $this->effectiveFrom())
            ->exists();

        if ($exists) {
            return;
        }

        ScheduleAssignment::create([
            'business_id' => $business->id,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'schedule_type' => $type,
            'shift_id' => $shiftId,
            'holiday_calendar_id' => $calendarId,
            'effective_from' => $this->effectiveFrom(),
            'is_temporary' => false,
        ]);
    }

    /**
     * When this configuration takes effect.
     *
     * ⚠ Configuration rather than a literal, because the go-live date is not
     * settled — and an assignment's effective date decides which schedule a day
     * is judged against. Set `HRM_GO_LIVE_DATE` once the date is agreed;
     * until then it provisions from today, which is harmless while no
     * attendance exists.
     */
    private function effectiveFrom(): string
    {
        $configured = config('hrm.go_live_date');

        return $configured === null
            ? Carbon::today()->toDateString()
            : Carbon::parse($configured)->toDateString();
    }
}
