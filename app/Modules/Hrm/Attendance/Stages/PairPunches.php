<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\PunchPair;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Enums\BreakPolicy;
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
        $context = $computation->context;
        $raw = $context->punches;

        if ($raw === []) {
            return $computation->note('No punches captured for this day.');
        }

        $punches = $this->withoutDuplicateScans($raw, $context->policy->duplicateScanWindowSeconds);
        $collapsed = count($raw) - count($punches);

        if ($collapsed > 0) {
            $computation = $computation->note(sprintf(
                '%d repeat scan%s within %d seconds treated as the same event; the punches themselves are unchanged.',
                $collapsed,
                $collapsed === 1 ? '' : 's',
                $context->policy->duplicateScanWindowSeconds,
            ));
        }

        if ($context->countsFirstPunchOnly() && count($punches) > 1) {
            $computation = $computation->note(sprintf(
                'Only the first punch counts on this schedule; %d later scan%s recorded but not read.',
                count($punches) - 1,
                count($punches) - 1 === 1 ? ' was' : 's were',
            ));
        }

        $pairs = match (true) {
            // One morning punch is the whole record for this schedule, so there
            // is nothing to pair and nothing later in the day to read.
            $context->countsFirstPunchOnly() => [new PunchPair($punches[0]->at, null)],
            $this->hasUsableDirections($punches) => $this->pairByDirection($punches),
            // Without directions the device cannot say which scan was a leaving
            // and which a returning, so an unpaid break cannot be measured from
            // punches; only where the shift asks for that is alternating right.
            $context->shift?->breakPolicy === BreakPolicy::ActualPunches => $this->pairByAlternating($punches),
            default => $this->pairByBookends($punches),
        };

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
            // Keeps the day identifiable after a late arrival rewrites the
            // status to Late and the Field duty label is no longer on the row.
            ->when($context->countsFirstPunchOnly(), fn (AttendanceComputation $c) => $c->flag('first_punch_only'))
            ->note(sprintf(
                '%d punch%s paired into %d span%s.',
                count($punches),
                count($punches) === 1 ? '' : 'es',
                count($pairs),
                count($pairs) === 1 ? '' : 's',
            ));
    }

    /**
     * Collapse a cluster of scans into the single event it really was.
     *
     * Each punch is compared with the last one KEPT, not with its immediate
     * predecessor: comparing against the predecessor lets a chain of scans four
     * minutes apart swallow an entire day, one link at a time. The earliest of
     * a cluster is the one retained, because that is when the person actually
     * presented themselves.
     *
     * @param  array<int, PunchRecord>  $punches  ordered by time
     * @return array<int, PunchRecord>
     */
    private function withoutDuplicateScans(array $punches, int $windowSeconds): array
    {
        if ($windowSeconds <= 0) {
            return $punches;
        }

        $kept = [];

        foreach ($punches as $punch) {
            $last = end($kept);

            if ($last !== false && $last->at->diffInSeconds($punch->at) <= $windowSeconds) {
                continue;
            }

            $kept[] = $punch;
        }

        return $kept;
    }

    /**
     * First punch in, last punch out, and nothing in between.
     *
     * Where the break is paid or deducted at a fixed amount, the scans between
     * arrival and departure change no figure the day is judged on, so reading
     * them as in/out/in/out only invents ways for the day to go wrong: an odd
     * count leaves a pair open and blocks payroll on a day that was worked in
     * full. ATT-06 ticked "first punch In, last punch Out" for precisely this.
     *
     * @param  array<int, PunchRecord>  $punches
     * @return array<int, PunchPair>
     */
    private function pairByBookends(array $punches): array
    {
        $first = $punches[0];
        $last = end($punches);

        // A single punch stays open: one scan genuinely cannot say when the
        // person left, and POL-07 wants that surfaced rather than guessed.
        return [new PunchPair($first->at, $last === $first ? null : $last->at)];
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
