<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use Illuminate\Support\Carbon;

/**
 * Turns a definition plus its parameters into a resolved `ReportDataset`.
 *
 * Three things happen here and nowhere else, which is what keeps the fifteen
 * definitions thin:
 *
 *  1. **The totals accumulator is spliced into the row stream.** A lazy `map`
 *     that feeds each row to `ReportTotals` as it passes and hands it straight
 *     on. Nothing is buffered, and the totals are the sum of exactly the rows
 *     the writer emitted — see `ReportTotals` for why a second aggregate query
 *     was the wrong answer.
 *  2. **Provenance is stamped on.** Who ran it, when, and under which business.
 *     A definition supplies the title and the lock state; it should not have to
 *     know who is signed in.
 *  3. **The row count is estimated** for Correction C10e's queued-vs-direct
 *     decision, without producing a row.
 */
class ReportRunner
{
    public function __construct(
        private readonly BusinessContext $context,
    ) {}

    public function run(ReportDefinition $definition, ReportParameters $parameters, ?User $user = null): ReportDataset
    {
        $totals = new ReportTotals($definition->totalColumns($parameters));

        $rows = $definition->rows($parameters)->map(function (array $row) use ($totals) {
            $totals->add($row);

            return $row;
        });

        return new ReportDataset(
            // Resolved once, here, and carried on the dataset — so a report
            // whose columns depend on its parameters cannot have the writer and
            // the row producer disagree about how many there are.
            columns: $definition->columns($parameters),
            rows: $rows,
            meta: $this->meta($definition, $parameters, $user),
            totals: $totals,
        );
    }

    /**
     * Correction C10e: how big is this going to be, cheaply.
     */
    public function estimate(ReportDefinition $definition, ReportParameters $parameters): int
    {
        return $definition->estimatedRows($parameters);
    }

    public function shouldQueue(ReportDefinition $definition, ReportParameters $parameters): bool
    {
        return $this->estimate($definition, $parameters) > $this->threshold();
    }

    public function threshold(): int
    {
        return (int) config('hrm.reporting.queue_threshold', 5000);
    }

    private function meta(ReportDefinition $definition, ReportParameters $parameters, ?User $user): ReportMeta
    {
        $declared = $definition->meta($parameters);

        return new ReportMeta(
            title: $declared->title,
            subtitle: $declared->subtitle,
            // The definition may describe its own filters; otherwise the
            // parameters describe themselves. Either way the rendered report
            // states what produced it.
            filters: $declared->filters !== [] ? $declared->filters : $parameters->describe(),
            businessName: $declared->businessName ?? $this->businessName(),
            generatedBy: $declared->generatedBy ?? $user?->name,
            generatedAt: $declared->generatedAt ?? Carbon::now(),
            periodLocked: $declared->periodLocked,
            lockNote: $declared->lockNote,
        );
    }

    private function businessName(): ?string
    {
        $id = $this->context->id();

        return $id === null ? null : Business::find($id)?->name;
    }

    /**
     * A filename that carries the report, the filters that produced it and the
     * moment it was taken. Two runs of the same report on different days must
     * not land in a downloads folder as `report.csv` and `report (1).csv` —
     * the one thing that makes an export untraceable a week later.
     */
    public function filename(ReportDefinition $definition, string $extension): string
    {
        return sprintf(
            '%s-%s.%s',
            str_replace('_', '-', $definition->key()),
            Carbon::now()->format('Ymd-His'),
            $extension,
        );
    }
}
