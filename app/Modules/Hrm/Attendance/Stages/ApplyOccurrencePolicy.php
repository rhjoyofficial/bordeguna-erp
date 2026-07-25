<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;

/**
 * Stage 8 — POL-03's tiered, occurrence-counted consequence.
 *
 * The real policy is not "late costs X". It is two independent monthly ladders:
 * arrivals inside the 15-minute grace are excused three times, warned on the
 * fourth and deducted from the fifth; arrivals from 15 to 150 minutes late are
 * excused once, warned, then warned-and-deducted, then escalate to disciplinary
 * action. Each band counts separately and both reset monthly.
 *
 * Both the bands and the ladders are policy data, so a revision is a new
 * versioned policy row rather than a code change (POL-12). The count of prior
 * occurrences arrives on the context — this stage stays pure and does not go
 * looking for it.
 */
class ApplyOccurrencePolicy implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        // Keyed off the measured lateness, not the final status. A late arrival
        // that also produced a short day resolves to Half day, and checking the
        // status here would let that day escape its consequence entirely —
        // exactly the conflation POL-02 warns against.
        if ($computation->lateMinutes <= 0) {
            return $computation;
        }

        if (! in_array($computation->status, [AttendanceStatus::Late, AttendanceStatus::HalfDay], true)) {
            return $computation;
        }

        $window = $computation->context->policy->windowFor($computation->lateMinutes);

        if ($window === null) {
            // Later than every configured band — a gap in the policy, not a
            // licence to charge nothing quietly.
            return $computation
                ->flag('outside_late_policy')
                ->flag('needs_review')
                ->note(sprintf(
                    '%d minutes late falls outside every configured lateness band — needs a human decision.',
                    $computation->lateMinutes,
                ));
        }

        $occurrence = $computation->context->priorOccurrencesFor($window->key) + 1;
        $consequence = $window->consequenceFor($occurrence);

        return $computation
            ->with([
                'graceWindowKey' => $window->key,
                'graceWindowLabel' => $window->label,
                'occurrenceNumber' => $occurrence,
                'consequence' => $consequence,
            ])
            ->note(sprintf(
                'Occurrence %d this month in "%s": %s.',
                $occurrence,
                $window->label,
                $consequence->label(),
            ))
            ->when(
                $consequence !== AttendanceConsequence::None,
                fn (AttendanceComputation $c) => $c->flag('late_consequence'),
            );
    }
}
