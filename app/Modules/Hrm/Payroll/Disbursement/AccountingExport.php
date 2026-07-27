<?php

namespace App\Modules\Hrm\Payroll\Disbursement;

use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use Illuminate\Database\Eloquent\Collection;

/**
 * The DIS-05 accounting export.
 *
 * DIS-05's own recommendation carried the ✔: an auditable export in Phase 1,
 * with journal posting from an approved payroll snapshot once an Accounting
 * module exists. So this posts nothing and knows nothing about a chart of
 * accounts — it hands Finance the figures in a form they can reconcile and key.
 *
 * Two views of the same run, because the two questions Finance actually asks are
 * different ones:
 *
 *  - **summary** — what this payroll cost, by component. The journal-entry shape,
 *    ready for whoever posts it.
 *  - **detail** — every line of every payslip, with the office and department it
 *    belongs to. This is the auditable half: a summary figure nobody can drill
 *    into is not auditable, and the office/department columns are what a later
 *    business-wise cost posting will group on without any of this being redone.
 *
 * Only calculated figures are exported and none is recomputed here. A payroll
 * export that disagrees with the payslips it came from would be worse than none.
 */
class AccountingExport
{
    public const SUMMARY = 'summary';

    public const DETAIL = 'detail';

    public function filename(PayrollRun $run, string $mode): string
    {
        return sprintf(
            'payroll-%s-v%d-%s.csv',
            str_replace(' ', '-', strtolower((string) $run->period?->label())),
            $run->version,
            $mode,
        );
    }

    public function render(PayrollRun $run, string $mode = self::SUMMARY): string
    {
        return $mode === self::DETAIL ? $this->detail($run) : $this->summary($run);
    }

    /**
     * One row per component across the whole run.
     */
    private function summary(PayrollRun $run): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Period', 'Run version', 'Component code', 'Component', 'Type', 'Employees', 'Amount']);

        $groups = [];

        foreach ($this->payslips($run) as $payslip) {
            foreach ($payslip->lines as $line) {
                $key = $line->type->value.'|'.$line->code;
                $groups[$key] ??= ['label' => $line->label, 'code' => $line->code, 'type' => $line->type->value, 'count' => 0, 'amount' => 0.0];
                $groups[$key]['count']++;
                $groups[$key]['amount'] += (float) $line->amount;
            }
        }

        ksort($groups);

        foreach ($groups as $group) {
            fputcsv($handle, [
                $run->period?->label(),
                $run->version,
                $group['code'],
                $group['label'],
                ucfirst($group['type']),
                $group['count'],
                number_format($group['amount'], 2, '.', ''),
            ]);
        }

        // The three run-level figures, so the export reconciles against the
        // payroll screen without anyone adding up the rows above.
        fputcsv($handle, []);
        fputcsv($handle, ['TOTALS', '', '', '', 'Earnings', $run->employee_count, number_format((float) $run->total_earnings, 2, '.', '')]);
        fputcsv($handle, ['', '', '', '', 'Deductions', '', number_format((float) $run->total_deductions, 2, '.', '')]);
        fputcsv($handle, ['', '', '', '', 'Net payable', '', number_format((float) $run->total_net, 2, '.', '')]);

        return $this->flush($handle);
    }

    /**
     * One row per payslip line, carrying where the cost belongs.
     */
    private function detail(PayrollRun $run): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Period', 'Run version', 'Employee code', 'Employee', 'Office', 'Department',
            'Component code', 'Component', 'Type', 'Quantity', 'Rate', 'Amount', 'Basis', 'Source',
        ]);

        foreach ($this->payslips($run) as $payslip) {
            $assignment = $payslip->employee?->currentAssignment();

            foreach ($payslip->lines as $line) {
                fputcsv($handle, [
                    $run->period?->label(),
                    $run->version,
                    $payslip->employee?->employee_code,
                    $payslip->employee?->fullName(),
                    $assignment?->office?->name,
                    $assignment?->department?->name,
                    $line->code,
                    $line->label,
                    ucfirst($line->type->value),
                    $line->quantity === null ? '' : (float) $line->quantity,
                    $line->rate === null ? '' : number_format((float) $line->rate, 2, '.', ''),
                    number_format((float) $line->amount, 2, '.', ''),
                    // PAY-13's stored formula travels with the figure, so an
                    // auditor never has to ask the system what a number meant.
                    $line->formula,
                    $line->source,
                ]);
            }
        }

        return $this->flush($handle);
    }

    /**
     * @return Collection<int, Payslip>
     */
    private function payslips(PayrollRun $run)
    {
        return $run->payslips()->with(['lines', 'employee'])->get();
    }

    /**
     * @param  resource  $handle
     */
    private function flush($handle): string
    {
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
