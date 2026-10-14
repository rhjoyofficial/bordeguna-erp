<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Carbon;
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
 *
 * ⚠⚠ DATES (FEA-001, 14 Oct 2026). `date_create('03/10/2026')` is PHP's
 * AMERICAN reading: 10 March. Bangladeshi banks write the day first, so every
 * day from 1 to 12 was silently swapped (12 Sep became 9 Dec, a future date)
 * and a 13th refused the whole file. The date COLUMN is now read as one
 * format: day first whenever every row allows it (the local convention, and
 * the reading used when the file cannot tell), month first only when a row
 * proves it (a second number above 12), and refused when rows disagree. The
 * format used is handed back so the screen can say which it was. A statement
 * date after today is refused: the bank cannot have done it yet.
 *
 * ⚠⚠ AMOUNTS (FEA-002, 14 Oct 2026). `(float) '(150.00)'` and
 * `(float) 'BDT 2500'` are 0, which was then skipped as "no movement", and
 * `(float) '1500.00 Dr'` is +1500, money IN. Amounts are now read strictly:
 * brackets and a minus are negative, a BDT / Tk / taka sign is stripped, a
 * "Dr" is money out and a "Cr" money in, and a cell that is not empty yet
 * cannot be read refuses the file naming its line. Only an empty cell or an
 * exact zero is skipped.
 */
class BankStatementFile
{
    private const DATE_HEADERS = ['date', 'txn date', 'txn_date', 'transaction date', 'value date', 'trans date'];

    private const AMOUNT_HEADERS = ['amount', 'txn amount', 'signed amount'];

    private const DEBIT_HEADERS = ['debit', 'withdrawal', 'withdrawal amt', 'debit amount', 'dr'];

    private const CREDIT_HEADERS = ['credit', 'deposit', 'deposit amt', 'credit amount', 'cr'];

    private const DESCRIPTION_HEADERS = ['description', 'particulars', 'narration', 'details', 'remarks'];

    private const REFERENCE_HEADERS = ['reference', 'ref', 'ref no', 'cheque no', 'chq no', 'instrument'];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** A currency word or sign written beside a figure. */
    private const CURRENCY = '(?:bdt|tk\.?|taka|\x{09F3})';

    /** How the date column was read, for the import message. */
    public const DAY_FIRST = 'day/month/year';

    public const MONTH_FIRST = 'month/day/year';

    public const UNAMBIGUOUS = 'year-month-day or a month name';

    /**
     * @return list<array{txn_date: string, description: ?string, reference: ?string, amount: float}>
     */
    public static function read(string $path): array
    {
        return self::parse($path)['rows'];
    }

    /**
     * The rows, and how their dates were read: `date_format` is one of the
     * three constants above, and `date_guessed` is true when every date in the
     * file could have been read either way round (day first was used).
     *
     * @return array{rows: list<array{txn_date: string, description: ?string, reference: ?string, amount: float}>, date_format: string, date_guessed: bool}
     */
    public static function parse(string $path): array
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

        $pending = [];
        $line = 1;

        try {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                if ($amountAt !== null) {
                    $amount = self::amount($values[$amountAt] ?? '', $line, null);
                } else {
                    // Debit = out, credit = in, from the account's point of view.
                    $amount = self::amount($values[$creditAt] ?? '', $line, 'credit') - self::amount($values[$debitAt] ?? '', $line, 'debit');
                }

                if ($amount === 0.0) {
                    continue; // a zero movement carries no information to match
                }

                $pending[] = [
                    'line' => $line,
                    'date' => self::dateParts((string) ($values[$dateAt] ?? ''), $line),
                    'description' => $descriptionAt !== null ? trim((string) ($values[$descriptionAt] ?? '')) ?: null : null,
                    'reference' => $referenceAt !== null ? trim((string) ($values[$referenceAt] ?? '')) ?: null : null,
                    'amount' => round($amount, 2),
                ];
            }
        } finally {
            fclose($handle);
        }

        [$format, $guessed] = self::dateFormatOf($pending);
        $today = Carbon::today()->toDateString();
        $rows = [];

        foreach ($pending as $row) {
            $date = self::resolveDate($row['date'], $format, $row['line']);

            if ($date > $today) {
                throw new RuntimeException("Line {$row['line']}: '{$row['date']['raw']}' reads as {$date}, which is after today. Check the file's date format.");
            }

            $rows[] = [
                'txn_date' => $date,
                'description' => $row['description'],
                'reference' => $row['reference'],
                'amount' => $row['amount'],
            ];
        }

        return ['rows' => $rows, 'date_format' => $format, 'date_guessed' => $guessed];
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

    /**
     * One date cell, taken apart but not yet decided: a numeric a/b/year cell
     * cannot be read until the whole column has been seen. For a `fixed` date
     * `a` is the month and `b` the day; for a `numeric` one the column decides.
     *
     * @return array{raw: string, kind: string, y: int, a: int, b: int}
     */
    private static function dateParts(string $cell, int $line): array
    {
        $raw = trim($cell);
        // A time after the date ("03/10/2026 14:05") says nothing about the day.
        $value = (string) preg_replace('/[\sT]+\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?$/', '', $raw);

        // 2026-10-03 or 2026/10/03: year first is never ambiguous.
        if (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/', $value, $m) === 1) {
            return ['raw' => $raw, 'kind' => 'fixed', 'y' => (int) $m[1], 'a' => (int) $m[2], 'b' => (int) $m[3]];
        }

        // 03/10/2026, 03-10-26, 03.10.2026: day or month first, decided per column.
        if (preg_match('/^(\d{1,2})([\/.\-])(\d{1,2})\2(\d{4}|\d{2})$/', $value, $m) === 1) {
            return ['raw' => $raw, 'kind' => 'numeric', 'y' => self::year($m[4]), 'a' => (int) $m[1], 'b' => (int) $m[3]];
        }

        // 03-Oct-2026, 03 Oct 2026, 3-October-26: a month name is never ambiguous.
        if (preg_match('/^(\d{1,2})[\s\/.\-]+([A-Za-z]{3,9})[\s\/.\-,]+(\d{4}|\d{2})$/', $value, $m) === 1
            && ($month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null) !== null) {
            return ['raw' => $raw, 'kind' => 'fixed', 'y' => self::year($m[3]), 'a' => $month, 'b' => (int) $m[1]];
        }

        // Oct 3, 2026
        if (preg_match('/^([A-Za-z]{3,9})[\s.\-]+(\d{1,2}),?[\s\-]+(\d{4})$/', $value, $m) === 1
            && ($month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null) !== null) {
            return ['raw' => $raw, 'kind' => 'fixed', 'y' => (int) $m[3], 'a' => $month, 'b' => (int) $m[2]];
        }

        throw new RuntimeException("Line {$line}: unreadable date '{$raw}'. Use a date like 03/10/2026 (day/month/year), 2026-10-03 or 03-Oct-2026.");
    }

    /**
     * Day first unless the column proves otherwise. Returns the format and
     * whether it was a choice the file itself could not settle.
     *
     * @param  list<array{line: int, date: array{raw: string, kind: string, y: int, a: int, b: int}}>  $rows
     * @return array{0: string, 1: bool}
     */
    private static function dateFormatOf(array $rows): array
    {
        $numeric = array_values(array_filter($rows, fn (array $row) => $row['date']['kind'] === 'numeric'));

        if ($numeric === []) {
            return [self::UNAMBIGUOUS, false];
        }

        $dayFirstFits = true;
        $monthFirstFits = true;
        $proof = [];

        foreach ($numeric as $row) {
            if ($row['date']['b'] > 12) {
                $dayFirstFits = false;
                $proof['month'] ??= $row;
            }

            if ($row['date']['a'] > 12) {
                $monthFirstFits = false;
                $proof['day'] ??= $row;
            }
        }

        if ($dayFirstFits) {
            return [self::DAY_FIRST, $monthFirstFits];
        }

        if ($monthFirstFits) {
            return [self::MONTH_FIRST, false];
        }

        throw new RuntimeException(
            "The dates disagree: line {$proof['day']['line']} ('{$proof['day']['date']['raw']}') can only be day/month/year, "
            ."and line {$proof['month']['line']} ('{$proof['month']['date']['raw']}') can only be month/day/year. Export the statement again with one date format.",
        );
    }

    /** @param array{raw: string, kind: string, y: int, a: int, b: int} $date */
    private static function resolveDate(array $date, string $format, int $line): string
    {
        [$month, $day] = $date['kind'] === 'numeric' && $format === self::DAY_FIRST
            ? [$date['b'], $date['a']]
            : [$date['a'], $date['b']];

        if (! checkdate($month, $day, $date['y'])) {
            throw new RuntimeException("Line {$line}: '{$date['raw']}' is not a real date when read as {$format}.");
        }

        return sprintf('%04d-%02d-%02d', $date['y'], $month, $day);
    }

    private static function year(string $digits): int
    {
        return strlen($digits) === 2 ? 2000 + (int) $digits : (int) $digits;
    }

    /**
     * One amount cell, signed from the account's point of view. In a debit or
     * credit column (`$column`) the column gives the direction and only the
     * size is read; a "Cr" in the debit column (or the reverse) is refused.
     */
    private static function amount(mixed $value, int $line, ?string $column): float
    {
        $raw = trim((string) $value);
        $text = $raw;

        if ($text === '' || $text === '-') {
            return 0.0;
        }

        // "Dr" is money out and "Cr" money in, written after or before the figure.
        $side = null;

        if (preg_match('/^(.*?)\s*\b(dr|cr)\.?$/i', $text, $m) === 1) {
            [$text, $side] = [$m[1], strtolower($m[2])];
        } elseif (preg_match('/^(dr|cr)\.?\s+(.*)$/i', $text, $m) === 1) {
            [$side, $text] = [strtolower($m[1]), $m[2]];
        }

        $text = self::withoutCurrency($text);
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $m) === 1) {
            [$negative, $text] = [true, self::withoutCurrency($m[1])];
        }

        if (str_starts_with($text, '-')) {
            [$negative, $text] = [! $negative, self::withoutCurrency(substr($text, 1))];
        } elseif (str_ends_with($text, '-')) {
            // Some exports print a debit as "150.00-".
            [$negative, $text] = [! $negative, trim(substr($text, 0, -1))];
        } elseif (str_starts_with($text, '+')) {
            $text = self::withoutCurrency(substr($text, 1));
        }

        $digits = str_replace([',', ' '], '', $text);

        if (preg_match('/^(\d+(\.\d+)?|\.\d+)$/', $digits) !== 1) {
            throw new RuntimeException("Line {$line}: unreadable amount '{$raw}'. Use a plain figure such as 1,500.00, (1,500.00), -1500 or 1,500.00 Dr.");
        }

        $size = (float) $digits;

        if ($column !== null) {
            if ($side !== null && $side !== ($column === 'debit' ? 'dr' : 'cr')) {
                throw new RuntimeException("Line {$line}: '{$raw}' is marked ".strtoupper($side)." but sits in the {$column} column.");
            }

            return $size;
        }

        if ($side !== null) {
            return $side === 'cr' ? $size : -$size;
        }

        return $negative ? -$size : $size;
    }

    private static function withoutCurrency(string $text): string
    {
        return trim((string) preg_replace('/^'.self::CURRENCY.'\s*|\s*'.self::CURRENCY.'$/iu', '', trim($text)));
    }
}
