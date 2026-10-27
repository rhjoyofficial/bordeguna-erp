<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AccrueMonthlyEarnedLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

/**
 * ⭐ 27 Oct 2026 (Joy): "reset full recount from aug 24 to sep 23, sep 24 to
 * oct 23 based on that add the amount, from the 24 oct to nov 23 will
 * calculate, main part is max time i will import the attendence end of the
 * month so no after i data update will be caculated when payroll runs."
 *
 * Pinned here: the one-time recount supersedes the calendar decisions for
 * 2026 months 8 and 9, reverses their grants and decides month 9 again on the
 * cycle; payroll calculation decides the month and re-decides it until the
 * run is approved; the ledger always nets to the decision that stands.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-27 09:00:00');

    $this->business = Business::create(['name' => 'Recount Co', 'slug' => 'recount-co']);
    app(BusinessContext::class)->set($this->business->id);

    // Payroll validation asks who may fix a day; the permission must exist.
    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);

    (new LeaveTypeSeeder)->run();
    (new SalaryComponentSeeder)->run();
    $this->earned = LeaveType::where('code', 'earned')->first();
    $this->earned->update(['is_active' => true]);

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    // 1 day a month, withheld above 3 ordinary or 1 serious late.
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => [
            ...AttendancePolicySeeder::defaultConfig(),
            'el_accrual' => ['enabled' => true, 'days_per_month' => 1, 'max_ordinary_lates' => 3, 'max_serious_lates' => 1],
        ],
        'effective_from' => '2026-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->makeEmployee = function (string $code) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Emp'.$code, 'last_name' => 'Test',
            'employment_type' => 'permanent', 'status' => 'active',
            'confirmation_date' => '2019-01-01',
            'bank_account_number' => '1234567890',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);
        app(SaveSalaryStructure::class)->handle($employee, '2025-01-01', [
            ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ]);

        return $employee;
    };

    // A serious late (09:40) with both punches.
    $this->seriousLate = function (Employee $employee, string $date) {
        app(RecordPunch::class)->handle($employee, "$date 09:40:00", PunchSource::Manual, [
            'direction' => PunchDirection::In, 'note' => 'test punch',
        ]);
        app(RecordPunch::class)->handle($employee, "$date 18:30:00", PunchSource::Manual, [
            'direction' => PunchDirection::Out, 'note' => 'test punch',
        ]);
        app(ProcessAttendance::class)->forDay($employee, $date, force: true);
    };

    // A calendar decision exactly as live holds it: decided on 1-31 Aug or
    // 1-30 Sep, its grant under the old once-key.
    $this->calendarDecision = function (Employee $employee, int $month, int $days, ?string $reason = null) {
        EarnedLeaveAccrual::create([
            'employee_id' => $employee->id, 'year' => 2026, 'month' => $month, 'basis' => 'calendar',
            'days_granted' => $days, 'withheld_reason' => $reason, 'ordinary_lates' => 0, 'serious_lates' => $reason ? 2 : 0,
        ]);

        if ($days > 0) {
            LeaveLedgerEntry::once("el_accrual:2026:$month:{$employee->id}", [
                'employee_id' => $employee->id, 'leave_type_id' => $this->earned->id, 'year' => 2026,
                'entry_type' => LeaveLedgerEntryType::Grant, 'days' => $days,
                'occurred_on' => Carbon::create(2026, $month)->endOfMonth()->toDateString(),
                'note' => 'Earned leave for '.Carbon::create(2026, $month)->format('F Y'),
            ]);
        }
    };

    $this->recount = fn () => (require base_path('app/Modules/Hrm/database/migrations/2026_10_27_150100_earned_leave_is_recounted_on_the_payroll_cycle.php'))->up();

    $this->balance = fn (Employee $employee) => app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026);
});

// --- The one-time recount ------------------------------------------------------

it('supersedes the calendar months 8 and 9, reverses their grants and decides month 9 on the cycle', function () {
    $lateInAugust = ($this->makeEmployee)('BDG-000001');
    $lateInSeptember = ($this->makeEmployee)('BDG-000002');

    ($this->calendarDecision)($lateInAugust, 8, 1);
    ($this->calendarDecision)($lateInAugust, 9, 1);
    ($this->calendarDecision)($lateInSeptember, 8, 0, '2 serious lates in the month, more than the 1 allowed.');
    ($this->calendarDecision)($lateInSeptember, 9, 1);

    // 25 and 26 Aug sit inside cycle 9 (24 Aug - 23 Sep); 24 and 26 Sep do not.
    ($this->seriousLate)($lateInAugust, '2026-08-25');
    ($this->seriousLate)($lateInAugust, '2026-08-26');
    ($this->seriousLate)($lateInSeptember, '2026-09-24');
    ($this->seriousLate)($lateInSeptember, '2026-09-26');

    expect(($this->balance)($lateInAugust))->toBe(2.0)
        ->and(($this->balance)($lateInSeptember))->toBe(1.0);

    ($this->recount)();

    // Every calendar row is kept, superseded, with the owner's decision quoted.
    $calendar = EarnedLeaveAccrual::where('basis', 'calendar')->get();
    expect($calendar)->toHaveCount(4)
        ->and($calendar->every(fn ($row) => $row->superseded_at !== null && str_contains($row->superseded_reason, '27 Oct 2026')))->toBeTrue()
        ->and($calendar->every(fn ($row) => $row->leave_ledger_entry_id === null))->toBeTrue();

    // Month 8 stays undecided; month 9 is decided once, on the cycle.
    expect(EarnedLeaveAccrual::forMonth(2026, 8)->current()->count())->toBe(0);

    $augustLate = EarnedLeaveAccrual::where('employee_id', $lateInAugust->id)->forMonth(2026, 9)->current()->sole();
    $septemberLate = EarnedLeaveAccrual::where('employee_id', $lateInSeptember->id)->forMonth(2026, 9)->current()->sole();

    expect($augustLate->basis)->toBe('cycle')
        ->and($augustLate->serious_lates)->toBe(2)
        ->and($augustLate->withheld_reason)->toContain('2 serious lates')
        ->and($septemberLate->serious_lates)->toBe(0)
        ->and((float) $septemberLate->days_granted)->toBe(1.0)
        ->and($septemberLate->leave_ledger_entry_id)->not->toBeNull();

    // Three grants reversed, one new grant: 2 - 2 + 0 and 1 - 1 + 1.
    $reversals = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Adjustment)->get();
    expect($reversals)->toHaveCount(3)
        ->and($reversals->every(fn ($entry) => (float) $entry->days === -1.0 && str_contains($entry->note, '27 Oct 2026')))->toBeTrue()
        ->and(($this->balance)($lateInAugust))->toBe(0.0)
        ->and(($this->balance)($lateInSeptember))->toBe(1.0);
});

it('changes nothing when the recount runs a second time', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    ($this->calendarDecision)($employee, 8, 1);
    ($this->calendarDecision)($employee, 9, 1);

    ($this->recount)();
    $entries = LeaveLedgerEntry::count();
    $decisions = EarnedLeaveAccrual::count();

    ($this->recount)();

    expect(LeaveLedgerEntry::count())->toBe($entries)
        ->and(EarnedLeaveAccrual::count())->toBe($decisions)
        ->and(($this->balance)($employee))->toBe(1.0);
});

it('leaves a business that never accrued on the calendar alone', function () {
    ($this->makeEmployee)('BDG-000001');

    ($this->recount)();

    expect(EarnedLeaveAccrual::count())->toBe(0)
        ->and(LeaveLedgerEntry::count())->toBe(0);
});

// --- Decided when payroll is calculated -------------------------------------------

it('decides month 10 when October payroll is calculated and shows it on the run', function () {
    $clean = ($this->makeEmployee)('BDG-000001');
    $late = ($this->makeEmployee)('BDG-000002');
    ($this->seriousLate)($late, '2026-09-24');
    ($this->seriousLate)($late, '2026-10-22');

    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 10));

    expect($run->status)->toBe(PayrollStatus::Calculated)
        ->and(($this->balance)($clean))->toBe(1.0)
        ->and(($this->balance)($late))->toBe(0.0)
        ->and(EarnedLeaveAccrual::where('employee_id', $late->id)->forMonth(2026, 10)->sole()->serious_lates)->toBe(2);

    $outcome = $run->validation_report['earned_leave'];
    expect($outcome['granted'])->toBe(1)
        ->and($outcome['withheld'])->toBe(1)
        ->and(collect($outcome['rows'])->firstWhere('employee_code', 'BDG-000002')['withheld_reason'])->toContain('2 serious lates');
});

it('re-decides on a recalculation after new lates and nets the ledger to the new decision', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    $period = PayrollPeriod::forMonth(2026, 10);

    app(CalculatePayroll::class)->handle($period);
    expect(($this->balance)($employee))->toBe(1.0);

    // The last days of the cycle are imported late: two serious lates.
    ($this->seriousLate)($employee, '2026-10-21');
    ($this->seriousLate)($employee, '2026-10-22');

    $run = app(CalculatePayroll::class)->handle($period);
    $decision = EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 10)->sole();

    expect(($this->balance)($employee))->toBe(0.0)
        ->and($decision->withheld_reason)->toContain('2 serious lates')
        ->and($decision->leave_ledger_entry_id)->toBeNull()
        ->and($run->validation_report['earned_leave']['changed'])->toBe(1);

    // A correction takes one late away again: granted afresh under its own key.
    DailyAttendance::where('employee_id', $employee->id)->onDate('2026-10-22')->sole()
        ->update(['grace_window_key' => null]);

    app(CalculatePayroll::class)->handle($period);

    expect(($this->balance)($employee))->toBe(1.0)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Grant)->pluck('source_key')->all())
        ->toBe(["el_accrual:cycle:2026:10:{$employee->id}", "el_accrual:cycle:2026:10:{$employee->id}:2"])
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Adjustment)->sum('days'))->toEqual(-1);
});

it('writes nothing new when payroll is recalculated on the same attendance', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    $period = PayrollPeriod::forMonth(2026, 10);

    app(CalculatePayroll::class)->handle($period);
    app(CalculatePayroll::class)->handle($period);
    $run = app(CalculatePayroll::class)->handle($period);

    expect(LeaveLedgerEntry::count())->toBe(1)
        ->and(EarnedLeaveAccrual::count())->toBe(1)
        ->and(($this->balance)($employee))->toBe(1.0)
        ->and($run->validation_report['earned_leave']['changed'])->toBe(0);
});

it('fixes the decision once the payroll is approved', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    $period = PayrollPeriod::forMonth(2026, 10);

    $run = app(CalculatePayroll::class)->handle($period);
    $run->update(['status' => PayrollStatus::Approved]);

    ($this->seriousLate)($employee, '2026-10-21');
    ($this->seriousLate)($employee, '2026-10-22');

    $result = app(AccrueMonthlyEarnedLeave::class)->handle(2026, 10, redecide: true);

    expect($result['frozen'])->toBeTrue()
        ->and($result['skipped'])->toBe(1)
        ->and(($this->balance)($employee))->toBe(1.0)
        ->and(EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 10)->sole()->withheld_reason)->toBeNull();
});

it('decides nothing for a cycle that has not ended and says so on the run', function () {
    Carbon::setTestNow('2026-11-10 09:00:00');
    ($this->makeEmployee)('BDG-000001');

    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 11));

    expect(EarnedLeaveAccrual::count())->toBe(0)
        ->and($run->validation_report['earned_leave']['reason'])->toContain('has not ended yet');
});

// ⭐ Joy: "leave year stay January to December". A cycle labelled January
// (24 Dec - 23 Jan) is earned in the leave year of its label.
it('keeps the leave year on the calendar year of the cycle label', function () {
    Carbon::setTestNow('2027-01-27 09:00:00');
    $employee = ($this->makeEmployee)('BDG-000001');

    app(AccrueMonthlyEarnedLeave::class)->handle(2027, 1);

    expect(LeaveLedgerEntry::sole()->year)->toBe(2027)
        ->and(app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026))->toBe(0.0);
});
