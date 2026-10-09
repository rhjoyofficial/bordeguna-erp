<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\AttendanceTrend;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\ReportScope;

/**
 * The HRM dashboard chart. Joy's complaint was that the dashboard had no
 * picture in it; the risk in answering that is a picture which disagrees with
 * the numbers beside it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Trend Co', 'slug' => 'trend-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->employee = Employee::create([
        'employee_code' => 'TRD-1', 'first_name' => 'Sabbir', 'last_name' => 'Rahman',
        'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01',
    ]);
});

function trendDay(int $employeeId, string $date, AttendanceStatus $status, ?int $lateMinutes = null): DailyAttendance
{
    return DailyAttendance::create([
        'employee_id' => $employeeId,
        'attendance_date' => $date,
        'status' => $status,
        'consequence' => AttendanceConsequence::None,
        'worked_minutes' => 480, 'payable_minutes' => 480, 'break_minutes' => 0,
        'late_minutes' => $lateMinutes ?? ($status === AttendanceStatus::Late ? 30 : 0), 'early_leave_minutes' => 0,
        'is_provisional' => false, 'holds_payroll' => false,
    ]);
}

function trendFilters(string $from, string $to): DashboardFilters
{
    return DashboardFilters::make(
        ['range_from' => $from, 'range_to' => $to],
        ReportScope::unrestricted(),
    );
}

it('draws a point for every day in the range, including empty ones', function () {
    trendDay($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    trendDay($this->employee->id, '2026-08-05', AttendanceStatus::Late);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    // Seven days asked for, seven bars drawn. A gap must stay a gap.
    expect($trend['points'])->toHaveCount(7)
        ->and($trend['points'][0]['date'])->toBe('2026-08-01')
        ->and($trend['points'][0]['present'])->toBe(0)
        ->and($trend['points'][2]['present'])->toBe(1)
        ->and($trend['points'][4]['late'])->toBe(1);
});

it('includes both boundary days', function () {
    trendDay($this->employee->id, '2026-08-01', AttendanceStatus::Present);
    trendDay($this->employee->id, '2026-08-07', AttendanceStatus::Present);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    expect($trend['rows'])->toBe(2)
        ->and($trend['points'][0]['present'])->toBe(1)
        ->and($trend['points'][6]['present'])->toBe(1);
});

it('counts field duty and half day as present rather than losing them', function () {
    trendDay($this->employee->id, '2026-08-03', AttendanceStatus::FieldDuty);
    trendDay($this->employee->id, '2026-08-04', AttendanceStatus::HalfDay);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    expect($trend['points'][2]['present'])->toBe(1)
        ->and($trend['points'][3]['present'])->toBe(1)
        ->and($trend['points'][2]['absent'])->toBe(0);
});

it('separates weekly off from absent', function () {
    trendDay($this->employee->id, '2026-08-03', AttendanceStatus::WeeklyOff);
    trendDay($this->employee->id, '2026-08-04', AttendanceStatus::Absent);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    expect($trend['points'][2]['off'])->toBe(1)
        ->and($trend['points'][2]['absent'])->toBe(0)
        ->and($trend['points'][3]['absent'])->toBe(1)
        ->and($trend['points'][3]['off'])->toBe(0);
});

it('names every status in the totals even at zero so the legend never moves', function () {
    trendDay($this->employee->id, '2026-08-03', AttendanceStatus::Present);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    foreach (AttendanceStatus::cases() as $case) {
        expect($trend['totals'])->toHaveKey($case->value);
    }

    expect($trend['totals'][AttendanceStatus::Absent->value])->toBe(0);
});

it('caps a very long range and says so instead of truncating silently', function () {
    $trend = AttendanceTrend::for(trendFilters('2026-01-01', '2026-08-31'), app(BusinessContext::class));

    expect($trend['truncated'])->toBeTrue()
        ->and($trend['days'])->toBe(62)
        ->and($trend['points'])->toHaveCount(62)
        // The cap keeps the END of the range, which is the part people are
        // looking at, not the beginning.
        ->and(end($trend['points'])['date'])->toBe('2026-08-31');
});

it('does not cap a range that fits', function () {
    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    expect($trend['truncated'])->toBeFalse()->and($trend['days'])->toBe(7);
});

it('counts late by late minutes, as the daily report does, so a late half day is late and not present', function () {
    $other = Employee::create([
        'employee_code' => 'TRD-2', 'first_name' => 'Nusrat', 'last_name' => 'Jahan',
        'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01',
    ]);
    trendDay($this->employee->id, '2026-08-03', AttendanceStatus::HalfDay, lateMinutes: 25);
    trendDay($other->id, '2026-08-03', AttendanceStatus::Present);

    $trend = AttendanceTrend::for(trendFilters('2026-08-01', '2026-08-07'), app(BusinessContext::class));

    expect($trend['points'][2]['late'])->toBe(1)
        ->and($trend['points'][2]['present'])->toBe(1);
});
