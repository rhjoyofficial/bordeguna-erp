<?php

namespace App\Modules\Hrm\Payroll\Disbursement\Formats;

use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Payroll\Disbursement\Contracts\DisbursementFormat;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementRow;
use Illuminate\Support\Collection;

/**
 * The disbursement register as a plain CSV (Correction C9d).
 *
 * Our own columns, in our own order, describing our own data. It is a register
 * HR can hand to Finance, open in Excel or use to key a transfer by hand — not
 * a bank advice, and it does not claim to be one. Every column is a fact the
 * system already holds, so nothing here is invented.
 *
 * Amounts are written as bare two-decimal numbers with no thousands separators
 * and no currency symbol, because that is the only rendering every spreadsheet
 * and every parser reads back as a number.
 */
class GenericCsvFormat implements DisbursementFormat
{
    public function key(): string
    {
        return 'generic_csv';
    }

    public function name(): string
    {
        return 'Generic CSV register';
    }

    public function description(): string
    {
        return 'A plain register of this batch in our own columns. Not a bank advice — the bank format is still awaiting the client\'s sample (DIS-06).';
    }

    public function filename(DisbursementBatch $batch): string
    {
        return sprintf('disbursement-register-%s.csv', strtolower($batch->reference));
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    /**
     * @param  Collection<int, DisbursementRow>  $rows
     */
    public function render(DisbursementBatch $batch, Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Employee code',
            'Employee name',
            'Payment method',
            'Bank name',
            'Bank branch',
            'Account name',
            'Account number',
            'Routing number',
            'MFS provider',
            'MFS account',
            'Amount',
            'Reference',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row->employeeCode,
                $row->employeeName,
                $row->method->label(),
                $row->bankName ?? '',
                $row->bankBranch ?? '',
                $row->bankAccountName ?? '',
                $row->bankAccountNumber ?? '',
                $row->bankRoutingNumber ?? '',
                $row->mfsProvider ?? '',
                $row->mfsAccountNumber ?? '',
                number_format($row->amount, 2, '.', ''),
                $row->reference ?? $batch->reference,
            ]);
        }

        // A total row, clearly labelled so it cannot be mistaken for a payee.
        fputcsv($handle, [
            'TOTAL', sprintf('%d payment(s)', $rows->count()), '', '', '', '', '', '', '', '',
            number_format($rows->sum(fn (DisbursementRow $row) => $row->amount), 2, '.', ''),
            $batch->reference,
        ]);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
