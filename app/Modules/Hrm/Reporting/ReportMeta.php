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
        /**
         * The three signature-row names, filled ONLY when the underlying
         * document actually records that the act happened (Joy, 2 Sep 2026:
         * "how added will prepared by, who review will be checked by, who
         * approve will be approve by; if anywhere data miss ... will be
         * empty"). A payroll run knows its calculator, reviewer and approver;
         * a plain data report knows none of them and keeps the blank lines.
         *
         * ⚠ This deliberately preserves the original caution: the printed
         * name asserts a recorded fact, never a prediction of who WILL sign.
         */
        public readonly ?string $preparedBy = null,
        public readonly ?string $checkedBy = null,
        public readonly ?string $approvedBy = null,
        /**
         * Inline signature images for the three lines, keyed
         * prepared|checked|approved, set only when the export asked for
         * digital signatures. Null = draw the blank rule for ink.
         */
        public readonly ?array $signatures = null,
    ) {}

    /**
     * A copy with NO signatory names and no images: the Prepared/Checked/Approved
     * block prints as three blank lines for wet ink (Joy, 27 Sep 2026). Used
     * when the export did not ask for digital signatures — then a name must not
     * appear at all, because a printed name with no signature reads as if that
     * person had already signed.
     */
    public function withoutSignatories(): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            filters: $this->filters,
            businessName: $this->businessName,
            generatedBy: $this->generatedBy,
            generatedAt: $this->generatedAt,
            periodLocked: $this->periodLocked,
            lockNote: $this->lockNote,
            note: $this->note,
            logo: $this->logo,
            preparedBy: null,
            checkedBy: null,
            approvedBy: null,
            signatures: null,
        );
    }

    /** A copy of this meta carrying signature images; the original stays as it is. */
    public function withSignatures(?array $signatures): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            filters: $this->filters,
            businessName: $this->businessName,
            generatedBy: $this->generatedBy,
            generatedAt: $this->generatedAt,
            periodLocked: $this->periodLocked,
            lockNote: $this->lockNote,
            note: $this->note,
            logo: $this->logo,
            preparedBy: $this->preparedBy,
            checkedBy: $this->checkedBy,
            approvedBy: $this->approvedBy,
            signatures: $signatures,
        );
    }

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
