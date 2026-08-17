<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\PunchRecord;

/**
 * Stage 4a — collapse repeat scans into the single events they really were
 * (Corrections C16, C18, C19, C20).
 *
 * Extracted from PairPunches (C20) because the meal resolver needs the same
 * cleaned list, and two private copies of the de-bounce would silently drift
 * apart. Both later stages read `cleanPunches`; the raw list on the context is
 * never touched (punches are immutable, ATT-03 — the collapse happens at
 * derivation time, the only layer a rule change can be re-run against).
 *
 * ⚠ C18: the window default fell 300 → 60 seconds when the four-punch day
 * arrived. At five minutes, an employee who punches out for lunch at 12:35 and
 * comes straight back for a forgotten phone at 12:38 has the return scan
 * silently discarded — a day punched correctly resolves to three candidates
 * and falls back. Sixty seconds is still 30-60× the device's observed re-read
 * interval (1-2 s in the real data), so every genuine double-scan is absorbed.
 *
 * ⚠ C19: a cluster resolves to the scan on the "inside the office" side of the
 * event — the reading that maximises measured presence. Keeping the earliest
 * everywhere (the old rule) records a double-scanned departure up to a window
 * early, which can push an innocent day past the 15-minute early-departure
 * grace. Since no break figure moves money, the employee-favourable reading
 * costs nothing and removes a whole class of dispute.
 */
class CollapseRepeatScans implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $raw = $computation->context->punches;

        if ($raw === []) {
            return $computation;
        }

        $clusters = $this->clusters($raw, $computation->context->policy->duplicateScanWindowSeconds);
        $kept = $this->resolve($clusters);
        $collapsed = count($raw) - count($kept);

        $computation = $computation->with(['cleanPunches' => $kept]);

        if ($collapsed > 0) {
            $computation = $computation->note(sprintf(
                '%d repeat scan%s within %d seconds treated as the same event; the punches themselves are unchanged.',
                $collapsed,
                $collapsed === 1 ? '' : 's',
                $computation->context->policy->duplicateScanWindowSeconds,
            ));
        }

        return $computation;
    }

    /**
     * Group scans into clusters of one physical event.
     *
     * Membership is measured against the cluster's FIRST scan, not the previous
     * one — C16's original guard, kept deliberately: chaining against the
     * predecessor lets a run of scans a minute apart swallow an entire day one
     * link at a time.
     *
     * @param  array<int, PunchRecord>  $punches  ordered by time
     * @return array<int, array<int, PunchRecord>>
     */
    private function clusters(array $punches, int $windowSeconds): array
    {
        if ($windowSeconds <= 0) {
            return array_map(fn (PunchRecord $punch) => [$punch], $punches);
        }

        $clusters = [];
        $current = [];

        foreach ($punches as $punch) {
            if ($current !== [] && $current[0]->at->diffInSeconds($punch->at) <= $windowSeconds) {
                $current[] = $punch;

                continue;
            }

            if ($current !== []) {
                $clusters[] = $current;
            }

            $current = [$punch];
        }

        $clusters[] = $current;

        return $clusters;
    }

    /**
     * C19: pick each cluster's representative by its position in the day.
     *
     * First cluster is the arrival (keep earliest — inside from that moment),
     * last is the departure (keep latest — inside until that moment). Middle
     * clusters alternate leaving/returning by physical necessity — nobody
     * leaves twice without returning — so odd middles keep the latest scan and
     * even middles the earliest. A missed punch flips the parity for the
     * clusters after it, but the cost is bounded by the cluster's own width,
     * which is seconds.
     *
     * @param  array<int, array<int, PunchRecord>>  $clusters
     * @return array<int, PunchRecord>
     */
    private function resolve(array $clusters): array
    {
        $last = count($clusters) - 1;
        $kept = [];

        foreach ($clusters as $i => $cluster) {
            $kept[] = match (true) {
                $i === 0 => $cluster[0],
                $i === $last => end($cluster),
                // Middles: 1st, 3rd, ... are leavings (latest); 2nd, 4th, ...
                // are returnings (earliest).
                $i % 2 === 1 => end($cluster),
                default => $cluster[0],
            };
        }

        return $kept;
    }
}
