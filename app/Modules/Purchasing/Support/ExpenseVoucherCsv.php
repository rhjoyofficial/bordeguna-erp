<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucherLine;

/**
 * What the business spent, as a file somebody can open in Excel.
 *
 * ⚠⚠ ONE ROW PER LINE, NOT PER VOUCHER. A voucher covering hosting and a domain
 * is two amounts charged to two heads, and a file that collapsed it to one row
 * would show a total that agrees with the bank but not with the accounts. The
 * voucher number repeats down the rows, which is what lets a spreadsheet pivot
 * by head, by month or by payee without losing the link back to the document.
 *
 * ⚠ `voucher_total` repeats on every line of the same voucher and must NOT be
 * summed. It is there so a reader can see the whole payment while looking at
 * one of its parts; `amount` is the column that adds up.
 *
 * ⛔ NOT AN IMPORT FORMAT. Unlike the customer and price files, this does not
 * round-trip: an expense voucher IS money leaving a bank account, and a
 * spreadsheet that could create them in bulk is a spreadsheet that can spend
 * money in bulk. Recording one stays a deliberate act on a screen.
 */
class ExpenseVoucherCsv
{
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'voucher_number',
            'spent_on',
            'payee',
            'paid_from',
            'expense_account',
            'description',
            'amount',
            'voucher_total',
            'reference',
            'note',
            'receipts',
            'recorded_by',
        ];
    }

    /**
     * @param  iterable<ExpenseVoucher>  $vouchers
     */
    public static function export(iterable $vouchers): string
    {
        $rows = [];

        foreach ($vouchers as $voucher) {
            $lines = $voucher->lines;

            // ⚠ A voucher with no lines should not vanish from the file. It
            // still took money out of an account, and a reader looking for
            // where the money went needs to see that it has no explanation.
            if ($lines->isEmpty()) {
                $rows[] = self::row($voucher, null);

                continue;
            }

            foreach ($lines as $line) {
                $rows[] = self::row($voucher, $line);
            }
        }

        return self::toCsv($rows);
    }

    /**
     * @return array<string, string>
     */
    private static function row(ExpenseVoucher $voucher, ?ExpenseVoucherLine $line): array
    {
        return [
            'voucher_number' => (string) $voucher->voucher_number,
            // ⚠ toDateString(), not the cast. A `date` cast serialises in UTC
            // and Dhaka is UTC+6, so midnight local becomes the previous day.
            'spent_on' => $voucher->spent_on?->toDateString() ?? '',
            'payee' => $voucher->payee(),
            'paid_from' => (string) ($voucher->moneyAccount?->name ?? ''),
            'expense_account' => (string) ($line?->account?->label() ?? ''),
            'description' => (string) ($line?->description ?? ''),
            'amount' => $line === null ? '' : (string) (float) $line->amount,
            'voucher_total' => (string) (float) $voucher->total,
            'reference' => (string) ($voucher->reference ?? ''),
            'note' => (string) ($voucher->note ?? ''),
            // The names of the proof, so a reader can tell a documented payment
            // from an undocumented one without opening the system.
            'receipts' => $voucher->attachments
                ->map(fn ($file) => $file->original_name ?? $file->file_name)
                ->implode('; '),
            'recorded_by' => (string) ($voucher->createdBy?->name ?? ''),
        ];
    }

    /**
     * @param  list<array<string,string>>  $rows
     */
    public static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // ⚠⚠ A UTF-8 BOM, on purpose. Without it Excel opens the file in the
        // system codepage and renders every Bangla payee or note as mojibake,
        // and the person then "fixes" it by retyping.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::columns());

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::columns()));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
