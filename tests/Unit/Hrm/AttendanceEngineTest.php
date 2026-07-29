<?php

use App\Modules\Hrm\Attendance\AttendanceEngine;
use App\Modules\Hrm\Attendance\Data\DayContext;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Attendance\Data\ShiftSpec;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\BreakPolicy;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleType;
use Illuminate\Support\Carbon;

/**
 * The engine is pure, so every rule below is exercised with no database at all
 * — these are plain fixtures in, deterministic result out.
 */
function engineShift(array $overrides = []): ShiftSpec
{
    $date = Carbon::parse($overrides['date'] ?? '2026-08-03');

    return new ShiftSpec(
        name: 'General',
        start: $date->copy()->setTimeFromTimeString($overrides['start'] ?? '09:00'),
        end: $date->copy()->setTimeFromTimeString($overrides['end'] ?? '18:00'),
        graceMinutes: 15,
        breakMinutes: $overrides['break_minutes'] ?? 60,
        breakPolicy: $overrides['break_policy'] ?? BreakPolicy::FixedDeduction,
        minimumPayableHours: $overrides['minimum_payable_hours'] ?? null,
        lateThresholdMinutes: null,
        earlyLeaveThresholdMinutes: null,
        isOvernight: $overrides['is_overnight'] ?? false,
        crossMidnightWindowMinutes: 0,
    );
}

function engineContext(array $punchTimes, array $overrides = []): DayContext
{
    $date = Carbon::parse($overrides['date'] ?? '2026-08-03');

    $punches = [];
    foreach ($punchTimes as $i => $time) {
        $punches[] = new PunchRecord(
            $i + 1,
            Carbon::parse($time),
            $overrides['directions'][$i] ?? null,
            PunchSource::Biometric,
        );
    }

    return new DayContext(
        employeeId: 1,
        date: $date,
        punches: $punches,
        shift: array_key_exists('shift', $overrides) ? $overrides['shift'] : engineShift(['date' => $date->toDateString()]),
        scheduleType: $overrides['schedule_type'] ?? ScheduleType::Fixed,
        isWeeklyOff: $overrides['is_weekly_off'] ?? false,
        isHoliday: $overrides['is_holiday'] ?? false,
        holidayName: $overrides['holiday_name'] ?? null,
        policy: PolicyConfig::fromArray($overrides['policy'] ?? AttendancePolicySeeder::defaultConfig()),
        priorOccurrences: $overrides['prior'] ?? [],
        hasApprovedLeave: $overrides['leave'] ?? false,
        hasApprovedMovement: $overrides['movement'] ?? false,
        hasApprovedHolidayWork: $overrides['holiday_work_approved'] ?? false,
    );
}

function engineRun(array $punchTimes, array $overrides = [])
{
    return (new AttendanceEngine)->process(engineContext($punchTimes, $overrides));
}

// --- POL-03: the questionnaire's own worked example ---------------------------

it('reproduces the POL-03 worked example for arrivals inside the 15 minute grace', function () {
    // "First 3 late arrivals (09:00:01-09:15) are excused per month. The 4th
    // results in a verbal warning. From the 5th onward, a pro-rata one-day
    // salary deduction applies."
    $expected = [
        1 => AttendanceConsequence::None,
        2 => AttendanceConsequence::None,
        3 => AttendanceConsequence::None,
        4 => AttendanceConsequence::Warning,
        5 => AttendanceConsequence::Deduction,
        6 => AttendanceConsequence::Deduction,
    ];

    foreach ($expected as $occurrence => $consequence) {
        $outcome = engineRun(
            ['2026-08-03 09:10:00', '2026-08-03 18:00:00'],
            ['prior' => ['within_grace' => $occurrence - 1]],
        );

        expect($outcome->status)->toBe(AttendanceStatus::Late);
        expect($outcome->graceWindowKey)->toBe('within_grace');
        expect($outcome->occurrenceNumber)->toBe($occurrence);
        expect($outcome->consequence)
            ->toBe($consequence, "occurrence {$occurrence} in the grace band");
    }
});

it('reproduces the POL-03 worked example for arrivals between 09:15:01 and 11:30', function () {
    // "the first occurrence is excused, the second receives a warning, the third
    // receives a warning plus salary deduction, and the fourth may trigger
    // disciplinary action."
    $expected = [
        1 => AttendanceConsequence::None,
        2 => AttendanceConsequence::Warning,
        3 => AttendanceConsequence::WarningAndDeduction,
        4 => AttendanceConsequence::Disciplinary,
    ];

    foreach ($expected as $occurrence => $consequence) {
        $outcome = engineRun(
            ['2026-08-03 10:30:00', '2026-08-03 18:00:00'],
            ['prior' => ['late_arrival' => $occurrence - 1]],
        );

        expect($outcome->graceWindowKey)->toBe('late_arrival');
        expect($outcome->occurrenceNumber)->toBe($occurrence);
        expect($outcome->consequence)
            ->toBe($consequence, "occurrence {$occurrence} in the late band");
    }
});

it('counts the two lateness bands independently', function () {
    // Three arrivals already used inside the grace band must not push a first
    // 10:30 arrival up its own ladder.
    $outcome = engineRun(
        ['2026-08-03 10:30:00', '2026-08-03 18:00:00'],
        ['prior' => ['within_grace' => 3]],
    );

    expect($outcome->graceWindowKey)->toBe('late_arrival');
    expect($outcome->occurrenceNumber)->toBe(1);
    expect($outcome->consequence)->toBe(AttendanceConsequence::None);
});

it('places the band boundaries exactly where the policy states', function () {
    // 09:15:00 is still the grace band; 09:15:01 is the next one.
    $inGrace = engineRun(['2026-08-03 09:15:00', '2026-08-03 18:00:00']);
    $pastGrace = engineRun(['2026-08-03 09:15:01', '2026-08-03 18:00:00']);

    expect($inGrace->graceWindowKey)->toBe('within_grace');
    expect($pastGrace->graceWindowKey)->toBe('late_arrival');

    // 09:00:01 already counts as a late arrival, exactly as written.
    $justLate = engineRun(['2026-08-03 09:00:01', '2026-08-03 18:00:00']);
    expect($justLate->status)->toBe(AttendanceStatus::Late);
    expect($justLate->graceWindowKey)->toBe('within_grace');

    // Dead on time is not late at all.
    $onTime = engineRun(['2026-08-03 09:00:00', '2026-08-03 18:00:00']);
    expect($onTime->status)->toBe(AttendanceStatus::Present);
    expect($onTime->graceWindowKey)->toBeNull();
});

it('flags an arrival later than every configured band instead of charging nothing', function () {
    // 13:00 is past the 11:30 ceiling of the last band.
    $outcome = engineRun(['2026-08-03 13:00:00', '2026-08-03 18:00:00']);

    expect($outcome->flags)->toContain('outside_late_policy');
    expect($outcome->isProvisional)->toBeTrue();
});

// --- POL-02: status separate from consequence --------------------------------

it('keeps a late status without a consequence for an excused occurrence', function () {
    $outcome = engineRun(['2026-08-03 09:05:00', '2026-08-03 18:00:00']);

    expect($outcome->status)->toBe(AttendanceStatus::Late);
    expect($outcome->consequence)->toBe(AttendanceConsequence::None);
});

// --- ATT-06 pairing + POL-08 break -------------------------------------------

it('pairs first-in and last-out across a break', function () {
    $outcome = engineRun([
        '2026-08-03 09:00:00', '2026-08-03 13:00:00',
        '2026-08-03 13:45:00', '2026-08-03 18:00:00',
    ]);

    expect($outcome->firstIn->format('H:i'))->toBe('09:00');
    expect($outcome->lastOut->format('H:i'))->toBe('18:00');
    // 4h + 4h15 inside the spans; the 45 minute gap is already excluded.
    expect($outcome->workedMinutes)->toBe(495);
});

it('uses the device direction when the export provides one', function () {
    $outcome = engineRun(
        ['2026-08-03 09:00:00', '2026-08-03 18:00:00'],
        ['directions' => [PunchDirection::In, PunchDirection::Out]],
    );

    expect($outcome->workedMinutes)->toBe(540);
});

it('deducts a fixed break when the shift says so', function () {
    $outcome = engineRun(['2026-08-03 09:00:00', '2026-08-03 18:00:00']);

    expect($outcome->workedMinutes)->toBe(540);
    expect($outcome->breakMinutes)->toBe(60);
    expect($outcome->payableMinutes)->toBe(480);
});

it('deducts nothing when the shift treats the break as paid', function () {
    $outcome = engineRun(
        ['2026-08-03 09:00:00', '2026-08-03 18:00:00'],
        ['shift' => engineShift(['break_policy' => BreakPolicy::Paid])],
    );

    expect($outcome->payableMinutes)->toBe(540);
});

it('does not double-deduct when the break comes from the punches themselves', function () {
    $outcome = engineRun(
        [
            '2026-08-03 09:00:00', '2026-08-03 13:00:00',
            '2026-08-03 14:00:00', '2026-08-03 18:00:00',
        ],
        ['shift' => engineShift(['break_policy' => BreakPolicy::ActualPunches])],
    );

    // The hour away is already outside the spans; nothing further comes off.
    expect($outcome->workedMinutes)->toBe(480);
    expect($outcome->breakMinutes)->toBe(0);
    expect($outcome->payableMinutes)->toBe(480);
});

// --- POL-06 / POL-07 ----------------------------------------------------------

it('proposes absent but never finalises it', function () {
    $outcome = engineRun([]);

    expect($outcome->status)->toBe(AttendanceStatus::Absent);
    expect($outcome->isProvisional)->toBeTrue();
});

it('treats an unclosed pair as a missing punch that holds payroll', function () {
    $outcome = engineRun(['2026-08-03 09:00:00']);

    expect($outcome->status)->toBe(AttendanceStatus::MissingPunch);
    expect($outcome->holdsPayroll)->toBeTrue();
    expect($outcome->isProvisional)->toBeTrue();
});

it('does not guess a half day from a missing punch', function () {
    $outcome = engineRun(['2026-08-03 09:00:00']);

    expect($outcome->status)->not->toBe(AttendanceStatus::HalfDay);
    expect($outcome->status)->not->toBe(AttendanceStatus::Absent);
});

// --- POL-05 half day ----------------------------------------------------------

it('marks a short day as half day using the configured boundary', function () {
    $outcome = engineRun(['2026-08-03 09:00:00', '2026-08-03 13:00:00']);

    // 4h worked, less the 60 minute break = 3h payable, under the 4h boundary.
    expect($outcome->status)->toBe(AttendanceStatus::HalfDay);
});

it('honours a configured arrival cutoff for half day', function () {
    $config = AttendancePolicySeeder::defaultConfig();
    $config['half_day_arrival_after_minutes'] = 120;

    $outcome = engineRun(['2026-08-03 11:30:00', '2026-08-03 20:00:00'], ['policy' => $config]);

    expect($outcome->status)->toBe(AttendanceStatus::HalfDay);
});

// --- POL-09 / POL-10 ----------------------------------------------------------

it('does not count holiday work without approval', function () {
    $outcome = engineRun(
        ['2026-08-03 09:00:00', '2026-08-03 18:00:00'],
        ['is_holiday' => true, 'holiday_name' => 'Victory Day'],
    );

    expect($outcome->status)->toBe(AttendanceStatus::Holiday);
    expect($outcome->payableMinutes)->toBe(0);
    expect($outcome->flags)->toContain('unapproved_holiday_work');
});

it('counts holiday work once it is approved', function () {
    $outcome = engineRun(
        ['2026-08-03 09:00:00', '2026-08-03 18:00:00'],
        ['is_holiday' => true, 'holiday_work_approved' => true],
    );

    expect($outcome->payableMinutes)->toBe(480);
    expect($outcome->flags)->not->toContain('unapproved_holiday_work');
});

it('marks a weekly off day', function () {
    $outcome = engineRun([], ['is_weekly_off' => true]);

    expect($outcome->status)->toBe(AttendanceStatus::WeeklyOff);
    expect($outcome->isProvisional)->toBeFalse();
});

it('treats an approved movement as present', function () {
    $outcome = engineRun([], ['movement' => true]);

    expect($outcome->status)->toBe(AttendanceStatus::FieldDuty);
});

it('treats approved leave as leave rather than absent', function () {
    $outcome = engineRun([], ['leave' => true]);

    expect($outcome->status)->toBe(AttendanceStatus::OnLeave);
});

// --- SCH-01 / POL-04 ----------------------------------------------------------

it('does not judge a flexible schedule on punctuality', function () {
    $outcome = engineRun(
        ['2026-08-03 11:00:00', '2026-08-03 20:00:00'],
        ['schedule_type' => ScheduleType::Flexible],
    );

    expect($outcome->status)->toBe(AttendanceStatus::Present);
    expect($outcome->lateMinutes)->toBe(0);
    expect($outcome->consequence)->toBe(AttendanceConsequence::None);
});

it('allows an early departure inside the grace but flags one beyond it', function () {
    $withinGrace = engineRun(['2026-08-03 09:00:00', '2026-08-03 17:50:00']);
    expect($withinGrace->flags)->not->toContain('early_departure');

    $beyondGrace = engineRun(['2026-08-03 09:00:00', '2026-08-03 17:00:00']);
    expect($beyondGrace->earlyLeaveMinutes)->toBe(60);
    expect($beyondGrace->flags)->toContain('early_departure');
});

// --- SCH-03 overnight ---------------------------------------------------------

it('attributes an overnight shift to its start date', function () {
    $shift = engineShift(['start' => '22:00', 'end' => '06:00', 'is_overnight' => true, 'break_minutes' => 0]);

    $outcome = engineRun(
        ['2026-08-03 22:00:00', '2026-08-04 06:00:00'],
        ['shift' => $shift],
    );

    expect($outcome->date->toDateString())->toBe('2026-08-03');
    expect($outcome->workedMinutes)->toBe(480);
    expect($outcome->status)->toBe(AttendanceStatus::Present);
});

// --- Determinism and explainability ------------------------------------------

it('produces the same result every time from the same inputs', function () {
    $context = engineContext(['2026-08-03 09:07:00', '2026-08-03 18:00:00'], ['prior' => ['within_grace' => 4]]);

    $first = (new AttendanceEngine)->process($context);
    $second = (new AttendanceEngine)->process($context);

    expect($first->status)->toBe($second->status);
    expect($first->consequence)->toBe($second->consequence);
    expect($first->payableMinutes)->toBe($second->payableMinutes);
    expect($first->notes)->toBe($second->notes);
});

it('explains every figure it produced', function () {
    $outcome = engineRun(['2026-08-03 09:10:00', '2026-08-03 18:00:00'], ['prior' => ['within_grace' => 4]]);

    $explanation = implode(' ', $outcome->notes);

    expect($explanation)->toContain('paired');
    expect($explanation)->toContain('after the General start of 09:00');
    expect($explanation)->toContain('Occurrence 5');
    expect($explanation)->toContain('Salary deduction');
    expect($outcome->provenance())->toHaveKeys(['notes', 'flags', 'pairs', 'occurrence_number']);
});

it('still reports hours when no policy is configured', function () {
    $outcome = engineRun(
        ['2026-08-03 09:00:00', '2026-08-03 18:00:00'],
        ['policy' => []],
    );

    expect($outcome->workedMinutes)->toBe(540);
    expect($outcome->payableMinutes)->toBe(480);
});

// --- C13: field staff who never return to the device --------------------------

it('accepts a single morning punch as a worked day for field duty', function () {
    // ⚠⚠ The rule Bor De Guna's real operations forced. Their Retail Sales
    // Officers give fingerprint at the office each morning and finish from the
    // field — they never come back to the device. Under POL-07's blanket
    // pairing rule every one of their days became a blocking Missing Punch, so
    // payroll would have been held for them EVERY month, and the TA & DA they
    // earn per day present would not have been paid on days they worked.
    $outcome = engineRun(['2026-08-03 08:30:00'], ['schedule_type' => ScheduleType::FieldDuty]);

    expect($outcome->status)->toBe(AttendanceStatus::FieldDuty)
        ->and($outcome->holdsPayroll)->toBeFalse();
});

it('still blocks an unclosed pair for ordinary office staff', function () {
    // The other half of C13, and the more important half: the exemption is
    // scoped to schedules that expect no return. An office employee who clocked
    // in and never out is exactly the case POL-07 exists for — somebody either
    // forgot or left without saying, and a human must look.
    $outcome = engineRun(['2026-08-03 09:00:00'], ['schedule_type' => ScheduleType::Fixed]);

    expect($outcome->status)->toBe(AttendanceStatus::MissingPunch)
        ->and($outcome->holdsPayroll)->toBeTrue();
});

it('counts a flexible schedule single punch as present rather than field duty', function () {
    // Both schedules waive the closed-pair requirement, but they are not the
    // same fact: a flexible employee working odd hours in the office has not
    // been on field duty, and the status is what a payslip and a report will
    // say about their day.
    $outcome = engineRun(['2026-08-03 10:15:00'], ['schedule_type' => ScheduleType::Flexible]);

    expect($outcome->status)->toBe(AttendanceStatus::Present)
        ->and($outcome->holdsPayroll)->toBeFalse();
});

it('still proposes absent for a field duty employee who never punched at all', function () {
    // C13 waives the *pairing* requirement, never the requirement to turn up.
    // No punch is no evidence of work, and TA & DA is earned on days with
    // check-in data — so this day must not become payable by accident.
    $outcome = engineRun([], ['schedule_type' => ScheduleType::FieldDuty]);

    expect($outcome->status)->toBe(AttendanceStatus::Absent)
        ->and($outcome->isProvisional)->toBeTrue();
});

it('closes the pair normally when a field employee does return', function () {
    // The waiver is a fallback, not a bypass: when both punches exist the day
    // is measured from them as usual, so worked time stays real wherever it
    // can be known.
    $outcome = engineRun(
        ['2026-08-03 08:30:00', '2026-08-03 18:00:00'],
        ['schedule_type' => ScheduleType::FieldDuty],
    );

    expect($outcome->holdsPayroll)->toBeFalse()
        ->and($outcome->workedMinutes)->toBeGreaterThan(0);
});

// =============================================================================
// Correction C15b — an exceptional lateness band is held for HR review.
//
// This is what gives C15b its effect. `is_provisional` is payroll's ONLY gate
// (ValidatePayrollRun reads it; nothing in payroll reads finalized_at), so a
// band that needs review must produce a provisional day, not merely an
// unfinalised one.
// =============================================================================

/**
 * Bor De Guna's real ladder: three bands, only the third exceptional. Bands 1
 * and 2 deduct once their free occurrences are spent and are deliberately NOT
 * held for review.
 */
function bandedPolicy(): array
{
    return [
        ...AttendancePolicySeeder::defaultConfig(),
        'grace_windows' => [
            [
                'key' => 'slightly_late', 'label' => 'Up to 15 minutes late',
                'from_minutes' => 0, 'to_minutes' => 15,
                'tiers' => [
                    ['from_occurrence' => 1, 'consequence' => 'none'],
                    ['from_occurrence' => 4, 'consequence' => 'deduction'],
                ],
            ],
            [
                'key' => 'late_arrival', 'label' => '15 minutes to 3 hours late',
                'from_minutes' => 15, 'to_minutes' => 180,
                'tiers' => [
                    ['from_occurrence' => 1, 'consequence' => 'none'],
                    ['from_occurrence' => 3, 'consequence' => 'deduction'],
                ],
            ],
            [
                'key' => 'after_midday', 'label' => 'More than 3 hours late',
                'from_minutes' => 180, 'to_minutes' => null,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'deduction']],
                'requires_review' => true,
            ],
        ],
    ];
}

it('holds a band 3 day for review, provisionally, on the very first occurrence', function () {
    // 13:30 against a 09:00 shift is 270 minutes late.
    $outcome = engineRun(['2026-08-03 13:30:00', '2026-08-03 18:00:00'], ['policy' => bandedPolicy()]);

    expect($outcome->graceWindowKey)->toBe('after_midday');
    expect($outcome->consequence)->toBe(AttendanceConsequence::Deduction);
    expect($outcome->isProvisional)->toBeTrue();
    expect($outcome->flags)->toContain('exceptional_lateness');
});

it('does not hold a band 1 deduction for review', function () {
    // Fifth occurrence in band 1: deducts a full day, and is still a
    // deterministic outcome of a configured ladder, so no review.
    $outcome = engineRun(
        ['2026-08-03 09:10:00', '2026-08-03 18:00:00'],
        ['policy' => bandedPolicy(), 'prior' => ['slightly_late' => 4]],
    );

    expect($outcome->consequence)->toBe(AttendanceConsequence::Deduction);
    expect($outcome->isProvisional)->toBeFalse();
    expect($outcome->flags)->not->toContain('exceptional_lateness');
});

it('does not hold a band 2 deduction for review', function () {
    $outcome = engineRun(
        ['2026-08-03 10:30:00', '2026-08-03 18:00:00'],
        ['policy' => bandedPolicy(), 'prior' => ['late_arrival' => 2]],
    );

    expect($outcome->graceWindowKey)->toBe('late_arrival');
    expect($outcome->consequence)->toBe(AttendanceConsequence::Deduction);
    expect($outcome->isProvisional)->toBeFalse();
});

it('leaves an unflagged band alone even when it deducts from the first occurrence', function () {
    // ⚠ The reason `requires_review` is stored rather than inferred from "no
    // free occurrences". This band deducts immediately and is NOT exceptional.
    $policy = bandedPolicy();
    $policy['grace_windows'][2]['requires_review'] = false;

    $outcome = engineRun(['2026-08-03 13:30:00', '2026-08-03 18:00:00'], ['policy' => $policy]);

    expect($outcome->consequence)->toBe(AttendanceConsequence::Deduction);
    expect($outcome->isProvisional)->toBeFalse();
});
