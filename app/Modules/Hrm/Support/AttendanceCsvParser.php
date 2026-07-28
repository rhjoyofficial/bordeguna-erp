<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\PunchDirection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads a ZKTeco K40 / ZKTime.Net-style attendance export (ATT-02) into rows
 * the importer can act on.
 *
 * The exact column titles vary by ZKTime version, locale and report template,
 * so the parser recognises the known aliases instead of hardcoding one layout.
 * When it cannot find a required column it fails loudly and lists the headers
 * it actually saw — per §0.5, fail early and explain, rather than import a
 * file's worth of silently wrong data.
 *
 * **The ATT-02 sample arrived on 2026-07-28 and its headers are now locked in
 * below** (`AllInOutData` from a ZKTeco K40, serial PAS4240900899, firmware
 * 6.60):
 *
 *     Department | Name | No. | Date/Time | Location ID | ID Number | VerifyCode | CardNo
 *
 * Three things it settled, each of which had been guessed at:
 *
 *  1. **The enroll column is `No.`** and there is a *second*, different identity
 *     column called `ID Number` — so "the id column" was never a single thing.
 *  2. **`Date/Time` is one combined column** in `M/d/yyyy h:mm AM/PM` — US
 *     month-first, which the ambiguous `d/m/Y` formats above it would otherwise
 *     have been free to misread.
 *  3. **There is no in/out status column at all.** The export is every punch in
 *     one list with nothing saying which is which — which is exactly why ATT-06
 *     did not tick "use the device status" and why `direction` is nullable.
 *     `VerifyCode` is *how* the person identified (fingerprint, card, password),
 *     not whether they were arriving or leaving.
 *
 * ⚠ **The sample is a binary `.xls` (BIFF8), and this parser reads CSV only.**
 * Locking the headers does not by itself make that file importable — see the
 * open item in `HRM-IMPLEMENTATION-PLAN.md`. Exporting the same report as CSV
 * from ZKTime.Net works today; reading the binary directly needs a new
 * dependency and therefore a decision, not an assumption.
 */
class AttendanceCsvParser
{
    /** @var array<int, string> */
    private const ENROLL_ALIASES = [
        'enrollid', 'enrollno', 'enrollmentid', 'employeeid', 'empid', 'userid',
        // `no` is the K40 sample's own enroll column and is matched ahead of the
        // generic fallbacks below, which is what keeps the two identity columns
        // in that file from being confused for each other.
        'user', 'acno', 'ac', 'no', 'personid', 'personnelid', 'personnelnumber',
        'badgenumber', 'badgeid', 'id',
        // ⚠ Last resort only. In the K40 sample `ID Number` sits *beside* `No.`
        // and holds a different value, so it must never win while a real enroll
        // column is present — matching it first would key every punch to the
        // wrong identifier and still import cleanly.
        'idnumber',
    ];

    /** @var array<int, string> */
    private const DATETIME_ALIASES = [
        'datetime', 'punchtime', 'checktime', 'attendancetime', 'timestamp',
        'datetimes', 'date_time', 'transactiontime', 'logtime', 'recordtime',
    ];

    /** @var array<int, string> */
    private const DATE_ALIASES = ['date', 'attendancedate', 'punchdate', 'logdate'];

    /** @var array<int, string> */
    private const TIME_ALIASES = ['time', 'punchtimeonly', 'clocktime'];

    /** @var array<int, string> */
    private const STATUS_ALIASES = [
        'status', 'state', 'checktype', 'inout', 'verifystate', 'attstate',
        'punchstate', 'type',
        // ⚠ `verifycode` was here and has been REMOVED. The sample showed what
        // it actually is: the verification *method* — 1 fingerprint, 3 password,
        // 4 card. Read as a direction it turns "identified by fingerprint" into
        // an arrival on every single row, which pairs punches wrongly and never
        // once looks like a parsing error.
    ];

    /** @var array<int, string> */
    private const DEVICE_ALIASES = [
        'device', 'devicename', 'machine', 'machinenumber', 'terminal',
        'terminalid', 'sn', 'serialnumber', 'deviceserial',
        // The K40 sample's own column. One physical device per location here,
        // so this is the identifier a duplicate check should key on.
        'locationid', 'location',
    ];

    /**
     * Date formats ZKTime exports are known to emit. Tried in order before
     * falling back to Carbon's own parsing, so an unambiguous format is never
     * misread as US month-first.
     *
     * @var array<int, string>
     */
    private const DATETIME_FORMATS = [
        // Unambiguous first: a four-digit year in front can only be read one way.
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i',
        // ⚠ The K40's own format, and it is placed **above** the `d/m/Y` group
        // deliberately. A 12-hour clock with AM/PM is the tell for a US-style
        // month-first date, and the file we have is month-first: read as
        // day-first, `7/15/2026` is a nonsense month that rolls silently over
        // into the following March rather than failing. Every date from the 1st
        // to the 12th would have been quietly transposed.
        'n/j/Y g:i:s A', 'n/j/Y g:i A', 'm/d/Y h:i:s A', 'm/d/Y h:i A',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i',
        'd.m.Y H:i:s', 'd.m.Y H:i', 'Y-m-d\TH:i:s',
    ];

    /**
     * @return array{rows: array<int, array<string, mixed>>, headers: array<int, string>}
     *
     * @throws ValidationException when the file has no usable header row
     */
    public function parse(string $contents): array
    {
        $lines = $this->readCsv($contents);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'file' => 'The file is empty.',
            ]);
        }

        $headers = array_shift($lines);
        $map = $this->mapColumns($headers);

        $rows = [];

        foreach ($lines as $index => $line) {
            // +2: one for the header row, one because humans count from 1.
            $rows[] = $this->parseRow($line, $map, $index + 2);
        }

        return ['rows' => $rows, 'headers' => $headers];
    }

    /**
     * @param  array<int, string>  $line
     * @param  array<string, int|null>  $map
     * @return array<string, mixed>
     */
    private function parseRow(array $line, array $map, int $number): array
    {
        $value = fn (?int $i) => $i !== null && isset($line[$i]) ? trim($line[$i]) : null;

        $enroll = $value($map['enroll']);
        $punchedAt = $this->resolveTimestamp(
            $value($map['datetime']),
            $value($map['date']),
            $value($map['time']),
        );

        return [
            'row' => $number,
            'enroll_id' => $enroll === '' ? null : $enroll,
            'punched_at' => $punchedAt,
            'direction' => $this->resolveDirection($value($map['status'])),
            'device_identifier' => $value($map['device']) ?: null,
            'raw' => $line,
        ];
    }

    /**
     * A single datetime column, or a separate date + time pair — both shapes
     * appear in the wild depending on the report template used.
     */
    private function resolveTimestamp(?string $datetime, ?string $date, ?string $time): ?Carbon
    {
        $candidate = $datetime ?: trim(($date ?? '').' '.($time ?? ''));
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return null;
        }

        foreach (self::DATETIME_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $candidate);

                if ($parsed !== false) {
                    return $parsed->startOfSecond();
                }
            } catch (\Throwable) {
                // Try the next known format before falling back.
            }
        }

        try {
            return Carbon::parse($candidate)->startOfSecond();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ZKTeco encodes direction inconsistently (text, or 0/1 codes). Anything
     * unrecognised stays null — ATT-06 pairs punches by policy, so an unknown
     * status is not a reason to guess or to drop the row.
     */
    private function resolveDirection(?string $status): ?PunchDirection
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        $normalised = Str::of($status)->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();

        $in = ['0', 'i', 'in', 'cin', 'checkin', 'clockin', 'dutyon', 'overtimein', 'entry'];
        $out = ['1', 'o', 'out', 'cout', 'checkout', 'clockout', 'dutyoff', 'overtimeout', 'exit'];

        return match (true) {
            in_array($normalised, $in, true) => PunchDirection::In,
            in_array($normalised, $out, true) => PunchDirection::Out,
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     *
     * @throws ValidationException
     */
    private function mapColumns(array $headers): array
    {
        $normalised = array_map(
            fn (string $header) => Str::of($header)->lower()->replaceMatches('/[^a-z0-9]/', '')->toString(),
            $headers,
        );

        $find = function (array $aliases) use ($normalised): ?int {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalised, true);

                if ($index !== false) {
                    return $index;
                }
            }

            return null;
        };

        $map = [
            'enroll' => $find(self::ENROLL_ALIASES),
            'datetime' => $find(self::DATETIME_ALIASES),
            'date' => $find(self::DATE_ALIASES),
            'time' => $find(self::TIME_ALIASES),
            'status' => $find(self::STATUS_ALIASES),
            'device' => $find(self::DEVICE_ALIASES),
        ];

        $seen = collect($headers)->filter()->implode(', ');

        if ($map['enroll'] === null) {
            throw ValidationException::withMessages([
                'file' => "Could not find an employee/enroll ID column. Columns found: {$seen}.",
            ]);
        }

        if ($map['datetime'] === null && $map['date'] === null) {
            throw ValidationException::withMessages([
                'file' => "Could not find a date or date-time column. Columns found: {$seen}.",
            ]);
        }

        return $map;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $contents): array
    {
        // Strip a UTF-8 BOM, which Excel-saved exports routinely carry and which
        // would otherwise corrupt the very first header name.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = $this->detectDelimiter($contents);
        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv yields [null] for a blank line.
            if ($line === [null] || $line === []) {
                continue;
            }

            $rows[] = array_map(fn ($value) => (string) ($value ?? ''), $line);
        }

        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
