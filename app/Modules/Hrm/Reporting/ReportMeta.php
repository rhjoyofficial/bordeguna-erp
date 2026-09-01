<?php

namespace App\Modules\Hrm\Reporting;

use Illuminate\Support\Carbon;

/**
 * Everything a rendered report says about itself.
 *
 * This is the plan's reproducibility answer, and it is deliberately *not* a
 * snapshot. Payroll reports read locked payslips, so they are stable by
 * construction; attendance reports are not, because a 2c correction can change
 * a past day retroactively and that is by design. Storing every rendering to
 * guarantee an identical re-run would cost more than it is worth.
 *
 * So instead each report states, on its own face: when it was generated, by
 * whom, and — where the data has a lock — **whether the period was locked at
 * the time**. Two runs of the same report that disagree can then be explained
 * (`AttendancePeriodLock` says the period was open; a correction landed
 * between them) instead of argued about. An unlocked period is not an error;
 * saying nothing about it would be.
 */
final class ReportMeta
{
    /**
     * @param  array<string, string>  $filters
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly array $filters = [],
        public readonly ?string $businessName = null,
        public readonly ?string $generatedBy = null,
        public readonly ?Carbon $generatedAt = null,
        /**
         * Null where the concept does not apply (a report over data that has no
         * period lock), true/false where it does. Three states, not two — "this
         * report has no lock concept" and "this period was open" are different
         * claims and must not collapse into one.
         */
        public readonly ?bool $periodLocked = null,
        public readonly ?string $lockNote = null,
        /**
         * A standing statement printed on every rendering of this report,
         * independent of its data.
         *
         * Added for W2's reconciliation (Correction C16b), which must carry
         * ROL-03's actual rule on the copy three people sign: one BDT is a
         * ceiling on decimal rounding, not a tolerance band, and no difference
         * caused by formula, attendance, leave or policy is acceptable at any
         * size. A caveat that lives only in the class docblock is a caveat the
         * signatories never see.
         *
         * ⚠ Distinct from `lockNote`, which describes this run's data. This
         * describes how the report must be read.
         */
        public readonly ?string $note = null,
        /**
         * The business's own logo, already inlined as a data URI by
         * `ReportLetterhead` so that dompdf and the browser draw the same
         * document. Null when the business has never uploaded one, and the
         * report simply prints without it.
         */
        public readonly ?string $logo = null,
    ) {}

    public function generatedAtString(): string
    {
        return ($this->generatedAt ?? Carbon::now())->format('d M Y, h:i A');
    }

    /**
     * The one-line provenance stamp that goes in every rendering's footer.
     */
    public function stamp(): string
    {
        $stamp = 'Generated '.$this->generatedAtString();

        if ($this->generatedBy !== null) {
            $stamp .= ' by '.$this->generatedBy;
        }

        if ($this->periodLocked === false) {
            $stamp .= ' — period NOT locked, figures may still change';
        } elseif ($this->periodLocked === true) {
            $stamp .= ' — period locked';
        }

        return $stamp;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'filters' => $this->filters,
            'business_name' => $this->businessName,
            'generated_by' => $this->generatedBy,
            'generated_at' => $this->generatedAtString(),
            'period_locked' => $this->periodLocked,
            'lock_note' => $this->lockNote,
            'note' => $this->note,
            'stamp' => $this->stamp(),
        ];
    }
}
