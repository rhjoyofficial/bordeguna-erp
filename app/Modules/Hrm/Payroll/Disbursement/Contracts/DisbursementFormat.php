<?php

namespace App\Modules\Hrm\Payroll\Disbursement\Contracts;

use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementRow;
use Illuminate\Support\Collection;

/**
 * How a disbursement register is written out (DIS-06, Correction C9d).
 *
 * ⚠ **DIS-06 is unresolved.** The client named Brac Bank and said a sample of
 * the required format was available; it has never been supplied. So there is
 * exactly one implementation of this interface — a generic CSV of our own
 * columns — and it is deliberately **not** called a bank advice. Guessing a
 * bank's column order, header row, date format and amount convention would
 * fabricate the one artefact the bank alone can dictate, and a file that is
 * *almost* right is worse than none: it fails at the counter, after payday.
 *
 * The interface exists now so that when the sample arrives it is a new class
 * beside `GenericCsvFormat` and nothing else moves — not the batches, not the
 * register, not the screens.
 */
interface DisbursementFormat
{
    /** Stable identifier used in routes and stored selections. */
    public function key(): string;

    /** What a human picks from a menu. */
    public function name(): string;

    /** A one-line statement of what this file is and is not. */
    public function description(): string;

    public function filename(DisbursementBatch $batch): string;

    public function mimeType(): string;

    /**
     * @param  Collection<int, DisbursementRow>  $rows
     */
    public function render(DisbursementBatch $batch, Collection $rows): string;
}
