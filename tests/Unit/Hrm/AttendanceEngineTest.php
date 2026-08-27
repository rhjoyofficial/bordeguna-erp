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
    // ⚠ Correction C16. This used to assert 495: the spans summed, with the
    // 45 minute gap excluded. That was wrong under a FIXED-DEDUCTION break,
    // which then subtracted a further 60 minutes — charging the same break
    // twice, once as measured and once as assumed. The day is 09:00 to 18:00.
    expect($outcome->workedMinutes)->toBe(540);
    expect($outcome->payableMinutes)->toBe(480);
});

it('measures the break from the punches when the shift says to', function () {
    $outcome = engineRun([
        '2026-08-03 09:00:00', '2026-08-03 13:00:00',
        '2026-08-03 13:45:00', '2026-08-03 18:00:00',
    ], ['shift' => engineShift(['break_policy' => BreakPolicy::ActualPunches])]);

    // Where the break IS punched, the alternating pairing still applies and the
    // 45 minute gap is excluded by construction. Nothing further is deducted.
    expect($outcome->workedMinutes)->toBe(495);
    expect($outcome->payableMinutes)->toBe(495);
});

it('reads a re-scanned finger as one arrival, not a one-second working day', function () {
    // The exact shape seen on production, 1 August 2026: the device caught the
    // same arrival twice, a single second apart, and the day recorded ZERO
    // minutes worked against a man who was there until 18:18.
    $outcome = engineRun([
        '2026-08-03 08:51:25', '2026-08-03 08:51:26', '2026-08-03 18:18:18',
    ]);

    expect($outcome->status)->not->toBe(AttendanceStatus::MissingPunch)
        ->and($outcome->holdsPayroll)->toBeFalse()
        ->and($outcome->firstIn->format('H:i:s'))->toBe('08:51:25')
        ->and($outcome->lastOut->format('H:i:s'))->toBe('18:18:18')
        ->and($outcome->workedMinutes)->toBe(566);
});

it('does not let a chain of scans swallow the whole day', function () {
    // Each scan is inside the window relative to the one before it, but the
    // cluster is measured from the last one KEPT, so the arrival stays at 09:00
    // and the 18:00 departure is never absorbed into it.
    $outcome = engineRun([
        '2026-08-03 09:00:00', '2026-08-03 09:04:00', '2026-08-03 09:08:00',
        '2026-08-03 09:12:00', '2026-08-03 18:00:00',
    ]);

    expect($outcome->firstIn->format('H:i'))->toBe('09:00')
        ->and($outcome->lastOut->format('H:i'))->toBe('18:00');
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

it('raises a holiday punch that was never clocked out, instead of ignoring it', function () {
    // ⚠⚠ THE 26 AUGUST 2026 CASE. Two people clocked in on Eid-e-Milad-un-Nabi
    // and never clocked out. Worked minutes were 0, so the holiday branch
    // returned before it could flag anything: the day looked exactly like a day
    // nobody came in at all. Nothing credited them and nothing asked anyone.
    $outcome = engineRun(
        ['2026-08-03 09:00:00'],
        ['is_holiday' => true, 'holiday_name' => 'Eid-e-Milad-un-Nabi'],
    );

    expect($outcome->status)->toBe(AttendanceStatus::Holiday)
        // ⚠ Still uncounted, deliberately: with no out punch the hours are
        // genuinely unknown, and guessing them would put invented numbers into
        // payroll. The point is that a human is now asked.
        ->and($outcome->payableMinutes)->toBe(0)
        ->and($outcome->flags)->toContain('unapproved_holiday_work')
        ->and($outcome->flags)->toContain('needs_review');
});

it('leaves a holiday with no punches at all completely alone', function () {
    // The counterweight to the test above: a genuine day off must not become
    // provisional, or every holiday would block payroll for all nine people.
    $outcome = engineRun([], ['is_holiday' => true, 'holiday_name' => 'Victory Day']);

    expect($outcome->status)->toBe(AttendanceStatus::Holiday)
        ->and($outcome->flags)->not->toContain('needs_review')
        ->and($outcome->isProvisional)->toBeFalse();
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
    // ⚠⚠ The rule Bor de Guna's real operations forced. Their Retail Sales
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

it('reads only the first punch of a field duty day, even when more arrive', function () {
    // ⚠ Correction C17. This used to assert that a returning field employee was
    // measured from both punches. Joy's instruction of 2026-08-16 replaces that:
    // "only count their first punch, if there is more then ignore them, because
    // they come to office and go to the field and complete their work outside".
    //
    // A Retail Sales Officer who happens to pass the device again in the evening
    // must not thereby be judged on hours the schedule never promised to capture.
    $outcome = engineRun(
        ['2026-08-03 08:30:00', '2026-08-03 18:00:00'],
        ['schedule_type' => ScheduleType::FieldDuty],
    );

    expect($outcome->holdsPayroll)->toBeFalse()
        ->and($outcome->firstIn->format('H:i'))->toBe('08:30')
        ->and($outcome->lastOut)->toBeNull()
        ->and($outcome->flags)->toContain('first_punch_only');
});

it('judges a field duty arrival against the shift it is assigned', function () {
    // ⚠⚠ Correction C17, the half of C13 that was wrong. Field duty used to
    // switch off punctuality as well as pair-closing. Circular BdG-HQ-OD-26071901
    // requires the Retail Sales Department inside the office by 08:15, so a
    // field-duty schedule that carries a shift is judged on arrival like anyone
    // else. Only a schedule with NO shift has no deadline to miss.
    $outcome = engineRun(
        ['2026-08-03 08:47:00'],
        [
            'schedule_type' => ScheduleType::FieldDuty,
            'shift' => engineShift(['start' => '08:15']),
        ],
    );

    expect($outcome->status)->toBe(AttendanceStatus::Late)
        ->and($outcome->lateMinutes)->toBe(32)
        ->and($outcome->holdsPayroll)->toBeFalse();
});

it('leaves a field duty schedule with no shift unjudged on time', function () {
    $outcome = engineRun(
        ['2026-08-03 11:30:00'],
        ['schedule_type' => ScheduleType::FieldDuty, 'shift' => null],
    );

    expect($outcome->status)->toBe(AttendanceStatus::FieldDuty)
        ->and($outcome->lateMinutes)->toBe(0);
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
 * Bor de Guna's real ladder: three bands, only the third exceptional. Bands 1
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

// --- The Exempt schedule: presence assumed, not evidenced ---------------------
//
// One step past C13 on the same axis. Flexible/FieldDuty are exempt from
// punctuality and pair-closing but still propose Absent on a punchless day
// (pinned above); Exempt exists for the people whose work the device cannot
// see - works from home, or the owner whose day is meetings. Joy's decision of
// 2026-08-15: every working day is active, no lateness, no deduction, punches
// still recorded when given.

it('treats an exempt working day with no punches as Present, never provisional', function () {
    $outcome = engineRun([], ['schedule_type' => ScheduleType::Exempt, 'shift' => null]);

    expect($outcome->status)->toBe(AttendanceStatus::Present);
    expect($outcome->isProvisional)->toBeFalse();
    expect($outcome->holdsPayroll)->toBeFalse();
    expect($outcome->flags)->toBe([]);
    expect($outcome->consequence)->toBe(AttendanceConsequence::None);
});

it('never judges an exempt employee on lateness, even with a shift and a late arrival', function () {
    // 10:30 against a 09:00 shift would be deep in a deduction band for Fixed.
    $outcome = engineRun(
        ['2026-08-03 10:30:00', '2026-08-03 18:00:00'],
        ['schedule_type' => ScheduleType::Exempt],
    );

    expect($outcome->status)->toBe(AttendanceStatus::Present);
    expect($outcome->lateMinutes)->toBe(0);
    expect($outcome->consequence)->toBe(AttendanceConsequence::None);
    expect($outcome->workedMinutes)->toBeGreaterThan(0);
});

it('accepts an exempt single punch without a missing-punch hold', function () {
    $outcome = engineRun(['2026-08-03 11:00:00'], ['schedule_type' => ScheduleType::Exempt]);

    expect($outcome->status)->toBe(AttendanceStatus::Present);
    expect($outcome->holdsPayroll)->toBeFalse();
    expect($outcome->flags)->not->toContain('payroll_hold');
});

it('still lets the calendar win for an exempt employee: a weekly off stays a weekly off', function () {
    $outcome = engineRun([], ['schedule_type' => ScheduleType::Exempt, 'shift' => null, 'is_weekly_off' => true]);

    expect($outcome->status)->toBe(AttendanceStatus::WeeklyOff);
    expect($outcome->isProvisional)->toBeFalse();
});

it('records an exempt weekend punch without payment, review or a payroll block', function () {
    // ⚠ The flag matters more than it looks: needs_review makes the day
    // provisional, and ONE provisional day blocks the whole payroll run. The
    // owner visiting the office on a Friday must not hold nine salaries.
    $outcome = engineRun(
        ['2026-08-07 11:00:00', '2026-08-07 13:00:00'],
        ['date' => '2026-08-07', 'schedule_type' => ScheduleType::Exempt, 'is_weekly_off' => true],
    );

    expect($outcome->status)->toBe(AttendanceStatus::WeeklyOff);
    expect($outcome->payableMinutes)->toBe(0);
    expect($outcome->workedMinutes)->toBeGreaterThan(0);
    expect($outcome->flags)->not->toContain('unapproved_holiday_work');
    expect($outcome->flags)->not->toContain('needs_review');
    expect($outcome->isProvisional)->toBeFalse();
});

it('still charges leave normally for an exempt employee', function () {
    $outcome = engineRun([], ['schedule_type' => ScheduleType::Exempt, 'shift' => null, 'leave' => true]);

    expect($outcome->status)->toBe(AttendanceStatus::OnLeave);
});
