<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyMovement;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Attendance\AttendanceEngine;
use App\Modules\Hrm\Attendance\DayContextBuilder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Support\WorkFromHomeAllowance;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Work from home (C-WFH-1 to C-WFH-4).
 *
 * ⚠⚠ THE DEFINING RULE IS C-WFH-2: a work-from-home movement AUTHORISES and
 * LABELS the day and does not attend it. Field duty stands in for punches
 * because nobody clocks in from a customer's warehouse; somebody at home has a
 * browser in front of them and clocks in like everybody else. Get this wrong
 * and every remote day is marked present whether the person worked or not,
 * which is exactly what the monthly cap is supposed to make impossible.
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Remote Co',
        'slug' => 'remote-co',
        'wfh_monthly_cap' => 2,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);
    $department = Department::create([
        'business_id' => $this->business->id, 'office_id' => $office->id, 'name' => 'Sales',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000041',
        'first_name' => 'Moin', 'last_name' => 'Chowdhury',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $office->id,
        'department_id' => $department->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->request = fn (string $date, MovementType $type = MovementType::WorkFromHome) => app(RequestMovement::class)->handle(
        $this->employee,
        $type,
        $date,
        ['reason' => 'Working from home', 'place' => 'Home'],
        $this->user,
    );
});

it('does not attend the day by itself, unlike field duty', function () {
    // ⚠⚠ C-WFH-2, the whole point of the slice.
    $movement = ($this->request)('2026-09-02');
    app(ApplyMovement::class)->handle($movement->fresh(), $this->user);

    $context = app(DayContextBuilder::class)->build($this->employee, Carbon::parse('2026-09-02'));

    expect($context->hasApprovedMovement)->toBeFalse()
        ->and($context->hasApprovedHolidayWork)->toBeFalse();
});

it('still attends the day for field duty, which the change must not have broken', function () {
    $movement = ($this->request)('2026-09-03', MovementType::FieldDuty);
    app(ApplyMovement::class)->handle($movement->fresh(), $this->user);

    $context = app(DayContextBuilder::class)->build($this->employee, Carbon::parse('2026-09-03'));

    expect($context->hasApprovedMovement)->toBeTrue();
});

it('leaves a work-from-home day with no punches exactly as unexplained as an office day', function () {
    // The consequence of C-WFH-2 stated as an outcome rather than a flag: the
    // day is proposed Absent and held for review, which is what stops a remote
    // day being paid on nobody's word.
    $movement = ($this->request)('2026-09-04');
    app(ApplyMovement::class)->handle($movement->fresh(), $this->user);

    $context = app(DayContextBuilder::class)->build($this->employee, Carbon::parse('2026-09-04'));
    $outcome = app(AttendanceEngine::class)->process($context);

    expect($outcome->status)->toBe(AttendanceStatus::Absent)
        ->and($outcome->flags)->toContain('needs_review');
});

it('counts requests still awaiting approval against the monthly cap', function () {
    // ⚠⚠ Otherwise eight requests filed in a morning each look fine on their own
    // and a manager approves them one at a time.
    ($this->request)('2026-09-07');
    ($this->request)('2026-09-08');

    expect(fn () => ($this->request)('2026-09-09'))
        ->toThrow(ValidationException::class, 'already used all 2 work-from-home day(s) for September 2026 (24 Aug to 23 Sep 2026)');
});

// ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23". This
// test used to pin the calendar month; it now pins the cycle boundary.
it('counts the 24-23 payroll cycle, not the calendar month', function () {
    // 24 Sep and 23 Oct are the two ends of the cycle labelled October.
    ($this->request)('2026-09-24');
    ($this->request)('2026-10-23');

    // 1 Oct is inside the same cycle; the cap is full, so this is refused.
    expect(fn () => ($this->request)('2026-10-01'))
        ->toThrow(ValidationException::class, 'October 2026 (24 Sep to 23 Oct 2026)');

    // 23 Sep closes the cycle before and 24 Oct opens the next one: both fine.
    expect(($this->request)('2026-09-23'))->not->toBeNull()
        ->and(($this->request)('2026-10-24'))->not->toBeNull();
});

it('refuses outright when the business allows no work from home at all', function () {
    $this->business->update(['wfh_monthly_cap' => 0]);

    expect(fn () => ($this->request)('2026-09-10'))
        ->toThrow(ValidationException::class, 'not allowed in this business');
});

it('does not cap the other movement types', function () {
    ($this->request)('2026-09-11', MovementType::FieldDuty);
    ($this->request)('2026-09-12', MovementType::FieldDuty);
    ($this->request)('2026-09-13', MovementType::OfficialWork);

    expect(($this->request)('2026-09-14', MovementType::FieldDuty))->not->toBeNull();
});

it('reports what is left this month', function () {
    ($this->request)('2026-09-15');

    $summary = app(WorkFromHomeAllowance::class)->summaryFor($this->employee, Carbon::parse('2026-09-20'));

    expect($summary)->toMatchArray(['cap' => 2, 'used' => 1, 'remaining' => 1, 'month' => 'September 2026 (24 Aug to 23 Sep 2026)']);
});

it('keeps work from home out of the holiday-work question', function () {
    // ⚠ Holiday work answers "does work on a non-working day count" (POL-09).
    // Work from home is silent on it, and must not be read as an answer.
    expect(MovementType::WorkFromHome->authorisesHolidayWork())->toBeFalse()
        ->and(MovementType::WorkFromHome->authorisesPresenceWithoutPunches())->toBeFalse()
        ->and(MovementType::HolidayWork->authorisesPresenceWithoutPunches())->toBeFalse()
        ->and(MovementType::FieldDuty->authorisesPresenceWithoutPunches())->toBeTrue()
        ->and(MovementType::OfficialWork->authorisesPresenceWithoutPunches())->toBeTrue();
});
