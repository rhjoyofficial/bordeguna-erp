<?php

namespace App\Modules\Hrm\Reporting\Concerns;

use App\Models\User;
use App\Modules\Hrm\Models\PayrollRun;

/**
 * The signature-row names for a report scoped to one payroll run.
 *
 * The run is the one document in HRM that records all three acts by name:
 * who calculated it, who reviewed it, who approved it. Those map onto the
 * printed Prepared by / Checked by / Approved by exactly (Joy, 2 Sep 2026),
 * and a stage the run has not reached yet stays null - the blank line is the
 * truthful statement that nobody has done it.
 */
trait SignsFromPayrollRun
{
    /**
     * Named so it can be spread straight into ReportMeta:
     *   new ReportMeta(..., ...$this->signatories($run))
     *
     * @return array{preparedBy: ?string, checkedBy: ?string, approvedBy: ?string}
     */
    private function signatories(?PayrollRun $run): array
    {
        if ($run === null) {
            return ['preparedBy' => null, 'checkedBy' => null, 'approvedBy' => null];
        }

        // One query for up to three ids; reviewed_by has no relation on the
        // model and does not need one for a name lookup.
        $ids = array_filter([$run->calculated_by, $run->reviewed_by, $run->approved_by]);
        $names = $ids === [] ? collect() : User::whereIn('id', $ids)->pluck('name', 'id');

        return [
            'preparedBy' => $run->calculated_by ? $names->get($run->calculated_by) : null,
            'checkedBy' => $run->reviewed_by ? $names->get($run->reviewed_by) : null,
            'approvedBy' => $run->approved_by ? $names->get($run->approved_by) : null,
        ];
    }
}
