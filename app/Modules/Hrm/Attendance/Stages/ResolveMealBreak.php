<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Enums\BreakPolicy;

/**
 * Stage 4c — resolve the meal break from the punches in the middle of the day
 * (Correction C21, MEAL-BREAK-ARCHITECTURE.md).
 *
 * The day has two layers, and they are never judged alike. The BOUNDARY layer
 * (first and last punch) answers "was this person at work" — a missing boundary
 * is serious, holds payroll, needs a correction. The MEAL layer (the punches
 * between) only answers "how much unpaid break comes out of that span" — a
 * missing meal punch has a safe fallback and a flag, never a hold.
 *
 * ⚠⚠ The safety contract, each clause pinned by a test: this stage never
 * touches pairs, firstIn, lastOut or hasOpenPair; it cannot change the status;
 * and it never raises `needs_review` (C24) — so no meal condition can ever make
 * a day provisional or block a payroll run. "Lunch must never block payroll"
 * is guaranteed by construction, not by a rule someone has to remember.
 */
class ResolveMealBreak implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $context = $computation->context;
        $shift = $context->shift;

        if ($shift?->breakPolicy !== BreakPolicy::MealWindow) {
            return $computation;
        }

        // Correction C25: configuration says Office only, but configuration is
        // not a guarantee. A schedule that does not measure hours has no span
        // to subtract from, and deducting a phantom hour from a one-punch field
        // day is exactly the kind of silent wrong number this engine exists to
        // prevent. The predicates already exist and are already tested.
        if (! $context->judgesHoursWorked() || $context->countsFirstPunchOnly() || $context->assumesPresence()) {
            return $computation;
        }

        // A day that is already a Missing Punch has no measurable span, and
        // adding a second problem to it helps nobody. The boundary layer's
        // existing machinery (POL-07) owns that day.
        if ($computation->hasOpenPair || $computation->firstIn === null || $computation->lastOut === null) {
            return $computation->note('Meal break not resolved: the day\'s boundary pair is not closed.');
        }

        if (! $shift->hasCompleteMealConfig()) {
            // A half-configured window must be visible, not silently zero-break.
            return $computation
                ->flag('meal_config_incomplete')
                ->note('Meal window mode is on but its configuration is incomplete; no meal was resolved.');
        }

        $standard = $shift->mealStandardMinutes;
        $allowance = $shift->mealAllowanceMinutes ?? $standard;

        // A window outside the shift is a configuration error, not an
        // attendance event — deduct the standard so the mistake shows up in the
        // numbers rather than producing zero-break days nobody questions.
        if ($shift->mealWindowStart->gte($shift->end) || $shift->mealWindowEnd->lte($shift->start)) {
            return $this->fallback(
                $computation,
                $standard,
                'meal_window_outside_shift',
                'The meal window lies outside the shift span — configuration needs review; the standard break was deducted.',
            );
        }

        [$candidates, $strays] = $this->candidates($computation);

        foreach ($strays as $stray) {
            $computation = $computation
                ->flag('punch_outside_meal_window')
                ->note(sprintf(
                    'Punch at %s sits between the day\'s boundaries but outside the %s-%s meal window; it was not read as a meal.',
                    $stray->at->format('H:i:s'),
                    $shift->mealWindowStart->format('H:i'),
                    $shift->mealWindowEnd->format('H:i'),
                ));
        }

        $count = count($candidates);

        // A lunch can only be deducted from a day it could physically have
        // happened on. Someone who left at 12:35 and never came back has a
        // 5-minute overlap with the window — deducting a standard hour from
        // their 3.5-hour morning would charge them for a meal they could not
        // have taken. The half-day and early-departure rules already own that
        // day; the meal layer stays out of it.
        if ($count === 0 && $this->windowOverlapMinutes($computation) < $standard) {
            return $computation->note(sprintf(
                'The worked span overlaps the meal window by less than the %d minute standard, so no meal was resolved.',
                $standard,
            ));
        }

        // The resolution table (Part 4). Every row deducts something sensible,
        // and no row blocks — that is decisions 2, 4 and 5 in a single match.
        $computation = match (true) {
            $count === 0 => $this->notPunched($computation, $shift->mealDeductWhenNotPunched, $standard),
            $count === 1 => $this->fallback(
                $computation,
                $standard,
                'meal_punch_incomplete',
                sprintf(
                    'One meal punch at %s — the other was not given, and nothing can say which. The standard %d minute break was deducted.',
                    $candidates[0]->at->format('H:i:s'),
                    $standard,
                ),
            ),
            $count % 2 === 1 => $this->fallback(
                $computation,
                $standard,
                'meal_punches_unreadable',
                sprintf(
                    '%d meal punches cannot be split into exits and returns; the standard %d minute break was deducted rather than guessed.',
                    $count,
                    $standard,
                ),
            ),
            default => $this->measured($computation, $candidates, $allowance),
        };

        return $this->checkOverrun($computation, $allowance);
    }

    /**
     * How many minutes of the worked span fall inside the meal window.
     */
    private function windowOverlapMinutes(AttendanceComputation $computation): int
    {
        $shift = $computation->context->shift;
        $from = $computation->firstIn->greaterThan($shift->mealWindowStart) ? $computation->firstIn : $shift->mealWindowStart;
        $to = $computation->lastOut->lessThan($shift->mealWindowEnd) ? $computation->lastOut : $shift->mealWindowEnd;

        return $to->greaterThan($from) ? (int) round($from->diffInSeconds($to) / 60) : 0;
    }

    /**
     * Split the punches strictly between the boundaries into meal candidates
     * (inside the window) and strays (outside it).
     *
     * @return array{0: array<int, PunchRecord>, 1: array<int, PunchRecord>}
     */
    private function candidates(AttendanceComputation $computation): array
    {
        $shift = $computation->context->shift;
        $inside = [];
        $outside = [];

        foreach ($computation->cleanPunches as $punch) {
            if ($punch->at->lte($computation->firstIn) || $punch->at->gte($computation->lastOut)) {
                continue;
            }

            if ($punch->at->between($shift->mealWindowStart, $shift->mealWindowEnd)) {
                $inside[] = $punch;
            } else {
                $outside[] = $punch;
            }
        }

        return [array_values($inside), array_values($outside)];
    }

    private function notPunched(AttendanceComputation $computation, bool $deduct, int $standard): AttendanceComputation
    {
        // Decision D1 (Joy, 2026-08-17): a silence is not an accusation, but it
        // is not extra credit either — the standard break is still deducted, or
        // not punching quietly becomes the way to be credited with more hours.
        if (! $deduct) {
            return $computation
                ->flag('meal_not_punched')
                ->with(['meal' => ['resolved_from' => 'not_punched', 'minutes' => 0]])
                ->note('No meal punches; this shift pays the break when it is not punched.');
        }

        return $this->fallback(
            $computation,
            $standard,
            'meal_not_punched',
            sprintf(
                'No meal punches — worked through, brought lunch, or forgot both scans. The standard %d minute break was deducted.',
                $standard,
            ),
        );
    }

    /**
     * The normal day and the multiple-exit day: even candidates, measured gaps.
     *
     * @param  array<int, PunchRecord>  $candidates
     */
    private function measured(AttendanceComputation $computation, array $candidates, int $allowance): AttendanceComputation
    {
        $minutes = 0;
        $spans = [];

        for ($i = 0; $i < count($candidates); $i += 2) {
            $out = $candidates[$i]->at;
            $in = $candidates[$i + 1]->at;
            $gap = (int) round($out->diffInSeconds($in) / 60);
            $minutes += $gap;
            $spans[] = ['out' => $out->format('H:i:s'), 'in' => $in->format('H:i:s'), 'minutes' => $gap];
        }

        $computation = $computation
            ->with([
                'breakMinutes' => $minutes,
                'meal' => [
                    'resolved_from' => 'punches',
                    'minutes' => $minutes,
                    'spans' => $spans,
                ],
            ])
            ->note(sprintf(
                'Meal break measured from punches: %s, %d minute%s in total.',
                implode(', ', array_map(fn (array $span) => $span['out'].'-'.$span['in'], $spans)),
                $minutes,
                $minutes === 1 ? '' : 's',
            ));

        if (count($candidates) > 2) {
            $computation = $computation
                ->flag('meal_multiple_exits')
                ->note(sprintf('Left %d times during the meal window.', count($candidates) / 2));
        }

        // A late return is recorded separately from an overrun: leaving at
        // 14:00 and returning at 15:10 breaks the window as well as the
        // allowance, and the report should be able to tell those apart.
        // The original instants, not re-parsed strings — Carbon::parse('12:35')
        // borrows today's date from the clock, and the engine reads no clock.
        $firstOut = $candidates[0]->at;
        $firstIn = $candidates[1]->at;

        if ($firstIn->greaterThan($firstOut->copy()->addMinutes($allowance))) {
            $lateBy = (int) round($firstOut->copy()->addMinutes($allowance)->diffInSeconds($firstIn) / 60);

            $computation = $computation
                ->flag('meal_late_return')
                ->note(sprintf(
                    'Returned %d minute%s after the %d minute meal allowance — feeds the show-cause process via the compliance report, no deduction (C24).',
                    $lateBy,
                    $lateBy === 1 ? '' : 's',
                    $allowance,
                ));
        }

        return $computation;
    }

    /**
     * Correction C24: an overrun is a flag and a counted report entry, never a
     * consequence and never money. `consequence` keeps meaning one thing —
     * arrival punctuality — so a lunch overrun can never displace a show-cause
     * for lateness backed by a written company order.
     */
    private function checkOverrun(AttendanceComputation $computation, int $allowance): AttendanceComputation
    {
        if ($computation->breakMinutes <= $allowance) {
            return $computation;
        }

        $over = $computation->breakMinutes - $allowance;

        return $computation
            ->flag('meal_overrun')
            ->with(['meal' => array_merge($computation->meal ?? [], ['overrun_minutes' => $over])])
            ->note(sprintf(
                'Meal ran %d minute%s over the %d minute allowance — flagged for the compliance report, no deduction beyond the measured break (C24).',
                $over,
                $over === 1 ? '' : 's',
                $allowance,
            ));
    }

    private function fallback(AttendanceComputation $computation, int $standard, string $flag, string $note): AttendanceComputation
    {
        return $computation
            ->flag($flag)
            ->with([
                'breakMinutes' => $standard,
                'meal' => ['resolved_from' => 'fallback', 'minutes' => $standard, 'cause' => $flag],
            ])
            ->note($note);
    }
}
