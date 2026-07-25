<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\PunchPair;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Enums\PunchDirection;

/**
 * Stage 4 — turn a day's punches into in/out pairs (ATT-06).
 *
 * ATT-06 ticked both "first punch In, last punch Out" and "multiple pairs with
 * break time", so pairing must support a day that looks like
 * in-out-in-out while still yielding a single first-in and last-out.
 *
 * Where the device reported a direction it is trusted; where it did not (ATT-06
 * deliberately did not tick "use the device status") the punches are simply
 * alternated in time order. An unclosed pair is left open rather than assumed —
 * POL-07 wants a missing punch surfaced, not guessed.
 */
class PairPunches implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $punches = $computation->context->punches;

        if ($punches === []) {
            return $computation->note('No punches captured for this day.');
        }

        $pairs = $this->hasUsableDirections($punches)
            ? $this->pairByDirection($punches)
            : $this->pairByAlternating($punches);

        $open = array_filter($pairs, fn (PunchPair $pair) => ! $pair->isClosed());

        $firstIn = $pairs === [] ? null : $pairs[0]->in;
        $closed = array_values(array_filter($pairs, fn (PunchPair $pair) => $pair->isClosed()));
        $lastOut = $closed === [] ? null : end($closed)->out;

        return $computation
            ->with([
                'pairs' => $pairs,
                'firstIn' => $firstIn,
                'lastOut' => $lastOut,
                'hasOpenPair' => $open !== [],
            ])
            ->note(sprintf(
                '%d punch%s paired into %d span%s.',
                count($punches),
                count($punches) === 1 ? '' : 'es',
                count($pairs),
                count($pairs) === 1 ? '' : 's',
            ));
    }

    /**
     * @param  array<int, PunchRecord>  $punches
     */
    private function hasUsableDirections(array $punches): bool
    {
        foreach ($punches as $punch) {
            if ($punch->direction === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, PunchRecord>  $punches
     * @return array<int, PunchPair>
     */
    private function pairByDirection(array $punches): array
    {
        $pairs = [];
        $openIn = null;

        foreach ($punches as $punch) {
            if ($punch->direction === PunchDirection::In) {
                // Two INs in a row: the first never closed. Keep it as an open
                // pair so the missing OUT is visible instead of vanishing.
                if ($openIn !== null) {
                    $pairs[] = new PunchPair($openIn, null);
                }

                $openIn = $punch->at;

                continue;
            }

            if ($openIn !== null) {
                $pairs[] = new PunchPair($openIn, $punch->at);
                $openIn = null;
            }
            // An OUT with no matching IN is dropped from pairing; the day is
            // still flagged below because no span opened it.
        }

        if ($openIn !== null) {
            $pairs[] = new PunchPair($openIn, null);
        }

        return $pairs;
    }

    /**
     * @param  array<int, PunchRecord>  $punches
     * @return array<int, PunchPair>
     */
    private function pairByAlternating(array $punches): array
    {
        $pairs = [];

        for ($i = 0; $i < count($punches); $i += 2) {
            $pairs[] = new PunchPair(
                $punches[$i]->at,
                isset($punches[$i + 1]) ? $punches[$i + 1]->at : null,
            );
        }

        return $pairs;
    }
}
