<?php

namespace App\Modules\Hrm\Reporting\Contracts;

use App\Modules\Hrm\Reporting\ReportDataset;

/**
 * How a resolved report becomes a file.
 *
 * Four implementations cover RPT-03's four ticked formats — but only three are
 * files. "Print-friendly" is a **view**, not a fourth format: it is the same
 * Blade the PDF writer renders, served to the browser for its own print
 * dialog. Rendering both from one template is what stops the printed page and
 * the PDF from disagreeing, which they otherwise will, quietly, about exactly
 * the thing someone is checking.
 *
 * ⚠ **A writer consumes `$dataset->rows` exactly once.** It is a
 * `LazyCollection` and may be a database cursor; iterating twice re-runs the
 * query at best and yields nothing at worst.
 *
 * ⚠ **A writer streams to a temporary file and returns its path** rather than
 * returning a string. `AccountingExport` and `GenericCsvFormat` (Phase 3) both
 * build their CSV in memory and return it, which is fine for one payroll run's
 * worth of rows and is exactly what Correction C10a decided not to repeat for
 * a monthly register.
 */
interface ReportWriter
{
    /** Stable identifier: 'csv', 'xlsx', 'pdf', 'print'. */
    public function key(): string;

    /** What a human picks from the format menu. */
    public function label(): string;

    public function extension(): string;

    public function mimeType(): string;

    /**
     * Whether this writer produces a download or a page to look at. The print
     * writer is the only one that is not a download, and the controller must
     * not send it as an attachment — a print view that arrives as a file the
     * browser saves cannot be printed, which is its entire purpose.
     */
    public function isDownload(): bool;

    /**
     * Render the dataset and return the **absolute path of a temporary file**
     * holding the result. The caller owns that file and is responsible for
     * deleting it after the response is sent.
     */
    public function write(ReportDataset $dataset): string;
}
