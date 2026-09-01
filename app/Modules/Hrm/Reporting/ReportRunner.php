<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
        $this->assertRequiredParameters($definition, $parameters);

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
     * A required parameter is required of every caller, not only of the form.
     *
     * ⚠⚠ FOUND IN THE CUTOVER REHEARSAL. The salary sheet filters payslips by
     * `run_id` only WHEN one is given, and a payroll period holds one set of
     * payslips per version: the rejected August v2 still carries its nine, next
     * to the nine of the live v3. Run without the parameter, the sheet returned
     * eighteen rows and totalled 489,830.66 for a month whose real net pay is
     * 239,048.66 - a money document, printed for signatures, quietly doubled.
     *
     * The screen validates from the same declarations and was never at risk.
     * This is the rule living with the engine instead of with one of its
     * callers, the same reasoning the supplier importer applies when it refuses
     * a plan with errors rather than trusting whoever called it.
     */
    private function assertRequiredParameters(ReportDefinition $definition, ReportParameters $parameters): void
    {
        foreach ($definition->parameters() as $parameter) {
            if (! $parameter->required) {
                continue;
            }

            if ($parameters->get($parameter->key) === null) {
                throw new InvalidArgumentException(
                    "The {$definition->key()} report needs {$parameter->key} and was run without it."
                );
            }
        }
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
            note: $declared->note,
            // ⚠ Resolved HERE, where the business context is known, rather than
            // in the template. A queued report renders inside a job that
            // restores the context it was given; a template reaching for
            // "the current business" would render the wrong letterhead, or
            // none, exactly when nobody is watching.
            logo: ReportLetterhead::logoFor($this->context->id()),
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
