<?php

use App\Modules\Hrm\Attendance\AttendanceEngine;
use App\Modules\Hrm\Attendance\Data\DayContext;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Attendance\Data\ShiftSpec;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\BreakPolicy;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleType;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Support\Carbon;

/**
 * MEAL-BREAK-ARCHITECTURE.md acceptance, pinned as fixtures — pure engine, no
 * database. The first group is the safety contract: each test must FAIL if the
 * guarantee "lunch never blocks payroll" is ever broken.
 */
function mealShift(array $overrides = []): ShiftSpec
{
    $date = Carbon::parse($overrides['date'] ?? '2026-09-02');

    return new ShiftSpec(
        name: 'Office',
        start: $date->copy()->setTimeFromTimeString($overrides['start'] ?? '09:00'),
        end: $date->copy()->setTimeFromTimeString($overrides['end'] ?? '18:00'),
        graceMinutes: 15,
        breakMinutes: $overrides['break_minutes'] ?? 60,
        breakPolicy: $overrides['break_policy'] ?? BreakPolicy::MealWindow,
        minimumPayableHours: null,
        lateThresholdMinutes: null,
        earlyLeaveThresholdMinutes: null,
        isOvernight: false,
        crossMidnightWindowMinutes: 0,
        mealWindowStart: $date->copy()->setTimeFromTimeString($overrides['meal_start'] ?? '12:30'),
        mealWindowEnd: $date->copy()->setTimeFromTimeString($overrides['meal_end'] ?? '15:00'),
        // array_key_exists, not ??: the config-incomplete test passes an
        // explicit null, and ?? would silently turn it back into 60.
        mealStandardMinutes: array_key_exists('meal_standard', $overrides) ? $overrides['meal_standard'] : 60,
        mealAllowanceMinutes: $overrides['meal_allowance'] ?? 60,
        mealDeductWhenNotPunched: $overrides['meal_deduct_when_not_punched'] ?? true,
        mealShortDayToleranceMinutes: $overrides['meal_tolerance'] ?? 5,
    );
}

function mealContext(array $punchTimes, array $overrides = []): DayContext
{
    $date = Carbon::parse($overrides['date'] ?? '2026-09-02');

    $punches = [];
    foreach ($punchTimes as $i => $time) {
        $punches[] = new PunchRecord($i + 1, Carbon::parse($time), null, PunchSource::Biometric);
    }

    return new DayContext(
        employeeId: 1,
        date: $date,
        punches: $punches,
        shift: array_key_exists('shift', $overrides) ? $overrides['shift'] : mealShift($overrides),
        scheduleType: $overrides['schedule_type'] ?? ScheduleType::Fixed,
        isWeeklyOff: $overrides['is_weekly_off'] ?? false,
        isHoliday: $overrides['is_holiday'] ?? false,
        holidayName: null,
        policy: PolicyConfig::fromArray($overrides['policy'] ?? AttendancePolicySeeder::defaultConfig()),
        priorOccurrences: [],
    );
}

function mealRun(array $punchTimes, array $overrides = [])
{
    return (new AttendanceEngine)->process(mealContext($punchTimes, $overrides));
}

// --- The safety contract: lunch never blocks payroll --------------------------

it('never holds payroll or goes provisional for any meal candidate count on a clean day', function () {
    // Acceptance 1: 0 through 6 candidates between healthy boundaries.
    $middles = [
        [],
        ['2026-09-02 12:35:00'],
        ['2026-09-02 12:35:00', '2026-09-02 13:30:00'],
        ['2026-09-02 12:35:00', '2026-09-02 13:00:00', '2026-09-02 13:30:00'],
        ['2026-09-02 12:35:00', '2026-09-02 12:50:00', '2026-09-02 13:10:00', '2026-09-02 13:25:00'],
        ['2026-09-02 12:35:00', '2026-09-02 12:50:00', '2026-09-02 13:10:00', '2026-09-02 13:25:00', '2026-09-02 13:40:00'],
        ['2026-09-02 12:35:00', '2026-09-02 12:50:00', '2026-09-02 13:05:00', '2026-09-02 13:20:00', '2026-09-02 13:35:00', '2026-09-02 13:50:00'],
    ];

    foreach ($middles as $count => $middle) {
        $outcome = mealRun(['2026-09-02 09:00:00', ...$middle, '2026-09-02 18:00:00']);

        expect($outcome->holdsPayroll)->toBeFalse("candidate count {$count} held payroll");
        expect($outcome->isProvisional)->toBeFalse("candidate count {$count} went provisional");
    }
});

it('raises no needs_review flag from any meal condition', function () {
    // Acceptance 2: the meal layer must never reach the provisional gate.
    $outcomes = [
        mealRun(['2026-09-02 09:00:00', '2026-09-02 18:00:00']),                                              // not punched
        mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00', '2026-09-02 18:00:00']),                       // incomplete
        mealRun(['2026-09-02 09:00:00', '2026-09-02 12:30:00', '2026-09-02 14:20:00', '2026-09-02 18:00:00']), // overrun + late return
    ];

    foreach ($outcomes as $outcome) {
        expect($outcome->flags)->not->toContain('needs_review');
        expect($outcome->isProvisional)->toBeFalse();
    }
});

it('leaves a one-lunch-punch day Present, payable, and finalisable', function () {
    // Acceptance 3.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00', '2026-09-02 18:00:00']);

    expect($outcome->status)->toBe(AttendanceStatus::Present);
    expect($outcome->breakMinutes)->toBe(60);
    expect($outcome->payableMinutes)->toBe(480);
    expect($outcome->flags)->toContain('meal_punch_incomplete');
    expect($outcome->isProvisional)->toBeFalse();
});

// --- Boundaries still behave --------------------------------------------------

it('still holds payroll for a missing boundary punch with the meal mode on', function () {
    // Acceptance 4: one punch, day never closed — POL-07 untouched.
    $outcome = mealRun(['2026-09-02 09:00:00']);

    expect($outcome->status)->toBe(AttendanceStatus::MissingPunch);
    expect($outcome->holdsPayroll)->toBeTrue();
    expect($outcome->isProvisional)->toBeTrue();
});

it('lets the boundary layer judge a lunch exit that never returned', function () {
    // Acceptance 5: out at 12:35, never back. The meal layer sees one
    // candidate? No — the 12:35 punch IS the last punch, so it is a boundary,
    // and the existing early-departure / half-day rules fire on their own.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00']);

    expect($outcome->status)->toBe(AttendanceStatus::HalfDay);
    expect($outcome->holdsPayroll)->toBeFalse();
    // 3h35m worked, no meal inside the boundaries to resolve.
    expect($outcome->breakMinutes)->toBe(0);
});

// --- The de-bounce corrections ------------------------------------------------

it('treats scans 90 seconds apart as two events and 2 seconds apart as one (C18)', function () {
    // 12:35:00 out and 12:36:30 back in: under the old 300s window the return
    // was silently discarded; at 60s both survive and the meal is measured.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00', '2026-09-02 12:36:30', '2026-09-02 18:00:00']);

    expect($outcome->breakMinutes)->toBe(2)
        ->and($outcome->flags)->not->toContain('meal_punch_incomplete');

    // The same two scans 2 seconds apart are one event — one candidate, fallback.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00', '2026-09-02 12:35:02', '2026-09-02 18:00:00']);

    expect($outcome->breakMinutes)->toBe(60)
        ->and($outcome->flags)->toContain('meal_punch_incomplete');
});

it('resolves an arrival cluster to its earliest scan and a departure cluster to its latest (C19)', function () {
    // Double-scan at both ends of the day.
    $outcome = mealRun([
        '2026-09-02 08:58:20', '2026-09-02 08:58:22',
        '2026-09-02 17:59:58', '2026-09-02 18:00:30',
    ]);

    expect($outcome->firstIn->format('H:i:s'))->toBe('08:58:20');
    expect($outcome->lastOut->format('H:i:s'))->toBe('18:00:30');
});

it('keeps the old earliest-kept rule for a single-cluster day', function () {
    // One arrival, re-scanned: the cluster is the whole record and it is an
    // arrival, so the earliest scan is the honest instant.
    $outcome = mealRun(['2026-09-02 09:10:00', '2026-09-02 09:10:02']);

    expect($outcome->firstIn->format('H:i:s'))->toBe('09:10:00');
    expect($outcome->status)->toBe(AttendanceStatus::MissingPunch);
});

// --- The arithmetic -----------------------------------------------------------

it('measures a punched lunch exactly', function () {
    // Acceptance 9: lunch 12:40-13:35 gives break 55, payable 485.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:40:00', '2026-09-02 13:35:00', '2026-09-02 18:00:00']);

    expect($outcome->breakMinutes)->toBe(55);
    expect($outcome->workedMinutes)->toBe(540);
    expect($outcome->payableMinutes)->toBe(485);
    expect($outcome->flags)->not->toContain('short_day');
});

it('falls back to the standard break when lunch was never punched', function () {
    // Acceptance 10.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 18:00:00']);

    expect($outcome->breakMinutes)->toBe(60);
    expect($outcome->payableMinutes)->toBe(480);
    expect($outcome->flags)->toContain('meal_not_punched');
});

it('pays the break on a not-punched day when the shift says so (D1 configurable)', function () {
    $outcome = mealRun(
        ['2026-09-02 09:00:00', '2026-09-02 18:00:00'],
        ['meal_deduct_when_not_punched' => false],
    );

    expect($outcome->breakMinutes)->toBe(0);
    expect($outcome->payableMinutes)->toBe(540);
    expect($outcome->flags)->toContain('meal_not_punched');
});

it('does not flag a perfect eight hour day as short (C23)', function () {
    // Acceptance 11: 540 scheduled − 60 meal = exactly 480 payable. Under the
    // flat 8-hour minimum this passes by zero margin; the derived minimum
    // (540 − 60 − 5 = 475) gives it honest room.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:30:00', '2026-09-02 13:31:00', '2026-09-02 18:00:00']);

    // A 61-minute lunch: payable 479, still not short.
    expect($outcome->breakMinutes)->toBe(61);
    expect($outcome->payableMinutes)->toBe(479);
    expect($outcome->flags)->not->toContain('short_day');
});

it('flags an overrun with its minutes and no consequence (C24)', function () {
    // Acceptance 12: lunch 12:30-14:10 = 100 minutes, 40 over the allowance.
    $outcome = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:30:00', '2026-09-02 14:10:00', '2026-09-02 18:00:00']);

    expect($outcome->breakMinutes)->toBe(100);
    expect($outcome->flags)->toContain('meal_overrun');
    expect($outcome->flags)->toContain('meal_late_return');
    expect($outcome->meal['overrun_minutes'])->toBe(40);
    expect($outcome->consequence->value)->toBe('none');
    expect($outcome->isProvisional)->toBeFalse();
});

it('sums multiple exits and flags them', function () {
    // Two trips: 12:35-12:50 and 13:10-13:25 = 30 minutes.
    $outcome = mealRun([
        '2026-09-02 09:00:00',
        '2026-09-02 12:35:00', '2026-09-02 12:50:00',
        '2026-09-02 13:10:00', '2026-09-02 13:25:00',
        '2026-09-02 18:00:00',
    ]);

    expect($outcome->breakMinutes)->toBe(30);
    expect($outcome->flags)->toContain('meal_multiple_exits');
});

it('falls back rather than guessing on an odd candidate count', function () {
    $outcome = mealRun([
        '2026-09-02 09:00:00',
        '2026-09-02 12:35:00', '2026-09-02 13:00:00', '2026-09-02 13:30:00',
        '2026-09-02 18:00:00',
    ]);

    expect($outcome->breakMinutes)->toBe(60);
    expect($outcome->flags)->toContain('meal_punches_unreadable');
});

it('flags a middle punch outside the meal window without reading it as a meal', function () {
    // 11:00 is before the window; the stated-limitation day carries both flags.
    $outcome = mealRun([
        '2026-09-02 09:00:00',
        '2026-09-02 11:00:00',
        '2026-09-02 14:00:00',
        '2026-09-02 18:00:00',
    ]);

    expect($outcome->flags)->toContain('punch_outside_meal_window');
    expect($outcome->flags)->toContain('meal_punch_incomplete');
    expect($outcome->breakMinutes)->toBe(60);
});

// --- Exclusions (C25) ---------------------------------------------------------

it('never resolves a meal for schedules and days that do not measure hours', function () {
    // Acceptance 13.
    $fieldDuty = mealRun(
        ['2026-09-02 08:20:00'],
        ['schedule_type' => ScheduleType::FieldDuty],
    );
    expect($fieldDuty->breakMinutes)->toBe(0);
    expect(array_intersect(DailyAttendance::MEAL_FLAGS, $fieldDuty->flags))->toBe([]);

    $exempt = mealRun([], ['schedule_type' => ScheduleType::Exempt]);
    expect($exempt->status)->toBe(AttendanceStatus::Present);
    expect($exempt->breakMinutes)->toBe(0);

    $weeklyOff = mealRun([], ['is_weekly_off' => true]);
    expect($weeklyOff->status)->toBe(AttendanceStatus::WeeklyOff);
    expect($weeklyOff->breakMinutes)->toBe(0);

    // A paid-break shift (Retail today) is untouched by the whole feature.
    $paid = mealRun(
        ['2026-09-02 08:10:00', '2026-09-02 12:40:00', '2026-09-02 13:40:00', '2026-09-02 18:00:00'],
        ['break_policy' => BreakPolicy::Paid],
    );
    expect($paid->breakMinutes)->toBe(0);
    expect(array_intersect(DailyAttendance::MEAL_FLAGS, $paid->flags))->toBe([]);
});

it('flags an incomplete meal configuration instead of silently resolving nothing', function () {
    $shift = mealShift(['meal_standard' => null]);
    $outcome = mealRun(
        ['2026-09-02 09:00:00', '2026-09-02 18:00:00'],
        ['shift' => $shift],
    );

    expect($outcome->flags)->toContain('meal_config_incomplete');
    expect($outcome->breakMinutes)->toBe(0);
    expect($outcome->isProvisional)->toBeFalse();
});

it('deducts lunch once on a meal-window shift even when the device tags punches In/Out', function () {
    $date = Carbon::parse('2026-09-02');
    $mk = fn (string $t, PunchDirection $d, int $id) => new PunchRecord($id, $date->copy()->setTimeFromTimeString($t), $d, PunchSource::Biometric);
    $context = new DayContext(
        employeeId: 1,
        date: $date,
        punches: [
            $mk('09:00:00', PunchDirection::In, 1),
            $mk('12:35:00', PunchDirection::Out, 2),
            $mk('13:35:00', PunchDirection::In, 3),
            $mk('18:00:00', PunchDirection::Out, 4),
        ],
        shift: mealShift(),
        scheduleType: ScheduleType::Fixed,
        isWeeklyOff: false,
        isHoliday: false,
        holidayName: null,
        policy: PolicyConfig::fromArray(AttendancePolicySeeder::defaultConfig()),
        priorOccurrences: [],
    );

    $outcome = (new AttendanceEngine)->process($context);

    // The 60-minute lunch (12:35-13:35) is deducted ONCE: 540-minute span - 60.
    // Before the fix, direction-pairing excluded the gap AND the meal was
    // subtracted again, giving 420.
    expect($outcome->breakMinutes)->toBe(60)
        ->and($outcome->payableMinutes)->toBe(480)
        ->and($outcome->status)->toBe(AttendanceStatus::Present)
        ->and($outcome->holdsPayroll)->toBeFalse();

    // And it matches the directionless equivalent exactly.
    $plain = mealRun(['2026-09-02 09:00:00', '2026-09-02 12:35:00', '2026-09-02 13:35:00', '2026-09-02 18:00:00']);
    expect($outcome->payableMinutes)->toBe($plain->payableMinutes);
});
