<?php

namespace App\Modules\Hrm\Dashboard;

use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\ReportValue;

/**
 * What a widget returns: a figure and everything needed to read it correctly.
 *
 * **Formatting reuses `ReportValue` and `ReportColumnType`**, so a money tile and
 * the money column of the report it drills into are formatted by the same
 * function. That is not tidiness — it is the difference between a tile reading
 * "৳45,000.00" beside a report reading "45000" and somebody deciding one of them
 * is broken. It also means W5's lakh-crore grouping, when it lands, arrives here
 * for nothing.
 *
 * **The `note` field is load-bearing rather than decorative.** Correction C11b
 * settled that an attendance tile must say how old it is, and POL-06/07 that a
 * provisional figure must say so. A number with no room to qualify itself is how
 * "0 present" gets read as "nobody came in" instead of "nothing has been
 * processed yet".
 *
 * **The drill-down is part of the value, not the page.** Every tile that can
 * point at a report does, and for the report-backed ones (C11a) the tile's
 * figure *is* that report's row count — so the link never leads somewhere that
 * disagrees with the tile that offered it.
 */
final class WidgetValue
{
    /**
     * @param  array<string, mixed>  $drilldownParameters
     */
    public function __construct(
        public readonly mixed $value,
        public readonly ReportColumnType $type = ReportColumnType::Integer,
        /** A movement against a comparable prior figure, where one exists. */
        public readonly ?float $delta = null,
        /**
         * ⚠ Null wherever a percentage would be a fabrication — no predecessor,
         * or a previous figure of zero. **C10c's rule, reapplied at tile grain
         * by C11c.** A joiner shown as "+100%" is a real-looking number
         * describing a non-event, and on a dashboard it is the first thing
         * anybody sees.
         */
        public readonly ?string $deltaPercent = null,
        public readonly ?string $deltaLabel = null,
        /** Freshness, provisionality, or why a comparison is absent. */
        public readonly ?string $note = null,
        public readonly ?string $drilldownReport = null,
        public readonly array $drilldownParameters = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'display' => ReportValue::forDisplay($this->value, $this->type),
            'type' => $this->type->value,
            'delta' => $this->delta,
            'delta_display' => $this->delta === null
                ? null
                : ($this->delta >= 0 ? '+' : '−').ReportValue::forDisplay(abs($this->delta), $this->type),
            'delta_percent' => $this->deltaPercent,
            'delta_label' => $this->deltaLabel,
            'note' => $this->note,
            'drilldown_report' => $this->drilldownReport,
            'drilldown_parameters' => $this->drilldownParameters,
        ];
    }
}
