<?php

namespace App\Modules\Accounting\Support;

use RuntimeException;

/**
 * Reads a bank statement CSV into signed rows (Module 2, Slice 3).
 *
 * Banks do not follow our template rules, so this reader is deliberately
 * more tolerant than the house importers: it accepts either a single signed
 * `amount` column or the debit/credit pair every Bangladeshi bank export
 * carries, and common header spellings of each. What it will NOT do is
 * guess silently - a file with no recognisable date or amount columns fails
 * loudly with the headers it found.
 *
 * ⚠ Sign convention: the returned `amount` is from the ACCOUNT's point of
 * view - positive is money in. On a bank's own statement, a CREDIT is money
 * into the account and a DEBIT is money out.
 */
class BankStatementFile
{
    private const DATE_HEADERS = ['date', 'txn date', 'txn_date', 'transaction date', 'value date', 'trans date'];

    private const AMOUNT_HEADERS = ['amount', 'txn amount', 'signed amount'];

    private const DEBIT_HEADERS = ['debit', 'withdrawal', 'withdrawal amt', 'debit amount', 'dr'];

    private const CREDIT_HEADERS = ['credit', 'deposit', 'deposit amt', 'credit amount', 'cr'];

    private const DESCRIPTION_HEADERS = ['description', 'particulars', 'narration', 'details', 'remarks'];

    private const REFERENCE_HEADERS = ['reference', 'ref', 'ref no', 'cheque no', 'chq no', 'instrument'];

    /**
     * @return list<array{txn_date: string, description: ?string, reference: ?string, amount: float}>
     */
    public static function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot read the statement file.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException('The statement file is empty.');
        }

        // Strip a UTF-8 BOM from the first header cell - bank exports love them.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $normalized = array_map(fn ($cell) => strtolower(trim((string) $cell)), $header);

        $dateAt = self::find($normalized, self::DATE_HEADERS);
        $amountAt = self::find($normalized, self::AMOUNT_HEADERS);
        $debitAt = self::find($normalized, self::DEBIT_HEADERS);
        $creditAt = self::find($normalized, self::CREDIT_HEADERS);
        $descriptionAt = self::find($normalized, self::DESCRIPTION_HEADERS);
        $referenceAt = self::find($normalized, self::REFERENCE_HEADERS);

        if ($dateAt === null || ($amountAt === null && ($debitAt === null || $creditAt === null))) {
            fclose($handle);

            throw new RuntimeException(
                'The statement needs a date column and either an amount column or a debit/credit pair. Found: '
                .implode(', ', $normalized),
            );
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $date = date_create(trim((string) ($values[$dateAt] ?? '')));

            if ($date === false) {
                throw new RuntimeException("Line {$line}: unreadable date '".($values[$dateAt] ?? '')."'.");
            }

            if ($amountAt !== null) {
                $amount = self::number($values[$amountAt] ?? '');
            } else {
                // Debit = out, credit = in, from the account's point of view.
                $amount = self::number($values[$creditAt] ?? '') - self::number($values[$debitAt] ?? '');
            }

            if ($amount === 0.0) {
                continue; // a zero movement carries no information to match
            }

            $rows[] = [
                'txn_date' => $date->format('Y-m-d'),
                'description' => $descriptionAt !== null ? trim((string) ($values[$descriptionAt] ?? '')) ?: null : null,
                'reference' => $referenceAt !== null ? trim((string) ($values[$referenceAt] ?? '')) ?: null : null,
                'amount' => round($amount, 2),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /** @param list<string> $header */
    private static function find(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $header, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    private static function number(mixed $value): float
    {
        $clean = str_replace([',', ' '], '', trim((string) $value));

        return $clean === '' ? 0.0 : (float) $clean;
    }
}
