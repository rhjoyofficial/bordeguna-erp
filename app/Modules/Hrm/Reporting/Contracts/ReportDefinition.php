<?php

namespace App\Modules\Hrm\Reporting\Contracts;

use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Support\LazyCollection;

/**
 * One report — the thin part of W3.
 *
 * A definition declares what it is called, who may run it, what it needs to be
 * told, and what its columns are; then it returns rows. It writes no file, no
 * form and no validation rule. That is the whole bargain: fifteen small
 * declarations instead of fifteen small applications.
 *
 * **Two rules a definition must not break.**
 *
 * 1. **`rows()` reads. It never recomputes.** The rule `AccountingExport`
 *    already states — "a payroll export that disagrees with the payslips it
 *    came from would be worse than none" — is the framework's rule now. A
 *    report that re-derives a figure has invented a second source of truth,
 *    and the two will differ eventually.
 * 2. **`rows()` returns a `LazyCollection`, backed by a cursor.** Building an
 *    array first defeats Correction C10a's streaming writer, and it will not
 *    fail until the data is large enough to matter.
 *
 * The plan named a separate `ReportQuery` seam. It collapsed into `rows()`
 * here: a class with one method, no state and exactly one caller is ceremony,
 * not a seam. The boundary the plan actually wanted — reading is separate from
 * rendering — is enforced by this interface returning rows and knowing nothing
 * about files.
 */
interface ReportDefinition
{
    /** Stable identifier used in routes, stored selections and queued jobs. */
    public function key(): string;

    /** What a human picks from the report list. */
    public function name(): string;

    /** One line stating what this report shows — and, where relevant, what it does not. */
    public function description(): string;

    /** The list grouping: 'Attendance' or 'Payroll'. */
    public function group(): string;

    /**
     * The permission required to run it (Correction C10d).
     *
     * Derived from the module whose data it reads — attendance reports need
     * `hrm.attendance.manage`, payroll and salary reports need
     * `hrm.payroll.manage` or `hrm.salary.view`. **C5 / ACL-02 is enforced on
     * top of this and is not negotiable: a Department Manager gets no payroll
     * or salary report at all.**
     */
    public function permission(): string;

    /** @return array<int, ReportParameter> */
    public function parameters(): array;

    /**
     * The columns, which may depend on what the report was asked for.
     *
     * ⚠ **The parameters are optional because the screen asks before the user
     * has chosen anything.** `null` means "describe yourself in general" — the
     * column list shown beside the filters. A run always passes them.
     *
     * Most reports ignore the argument entirely. Two cannot: the monthly
     * register's columns are the *days of the month it was asked for*, and the
     * earnings/deductions breakdown's are the components that appear in the run.
     * Both were forced by the data rather than chosen, so the shape belongs in
     * the interface rather than in a workaround — a register that always
     * printed 31 columns would show three empty ones every February, and a
     * breakdown with fixed component columns would be wrong for any business
     * that added one.
     *
     * @return array<int, ReportColumn>
     */
    public function columns(?ReportParameters $parameters = null): array;

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(ReportParameters $parameters): LazyCollection;

    /**
     * Roughly how many rows this run will produce, without producing them.
     *
     * Correction C10e uses this to decide between a direct download and a
     * queued job. It must be **cheap** — a `count()` on the same constrained
     * query, never a materialised result — and it is allowed to be
     * approximate, because it only ever picks which of two correct paths runs.
     */
    public function estimatedRows(ReportParameters $parameters): int;

    /**
     * Title, subtitle and the lock state, if this report's data has one.
     */
    public function meta(ReportParameters $parameters): ReportMeta;

    /**
     * Column totals, keyed by column key, or `[]` for a report where a total
     * is meaningless. A total under a column of dates is noise; a salary sheet
     * without one is unusable.
     *
     * Accumulated during the single pass over `rows()` by the runner, so a
     * definition declares only *which* columns total.
     *
     * Takes the parameters for the same reason `columns()` does: a report whose
     * columns are decided by its input cannot declare in advance which of them
     * total.
     *
     * @return array<int, string>
     */
    public function totalColumns(?ReportParameters $parameters = null): array;
}
