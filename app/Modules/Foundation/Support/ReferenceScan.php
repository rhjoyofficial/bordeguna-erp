<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 6.4 "Used or never used: one check", and the
 * Owner's merges in section 7): every row anywhere that points at a record.
 *
 * Two kinds of pointer are found from the schema itself, so a table added
 * next month is covered without anybody remembering this class:
 *
 *   direct       a `<name>_id` column (customer_id, supplier_id, account_id)
 *   polymorphic  a `<x>_type` + `<x>_id` pair holding the record's morph name
 *                (a journal line's party, a phone book contact's link, an
 *                attachment, a note)
 *
 * ⚠ The record's own table is never counted (a customer is not "used" by
 * itself), nor are the logs that only DESCRIBE it (activity log,
 * notifications, duplicate acknowledgements, the corrections log): history
 * stays with the record it was written about and never blocks a delete.
 *
 * ⚠ Read from the schema once per process; the scan is for a delete check, a
 * dry run and a merge, never for a list page.
 */
final class ReferenceScan
{
    /** Tables that describe a record without using it. */
    public const HISTORY_TABLES = [
        'activity_log', 'notifications', 'duplicate_acknowledgements', 'correction_links', 'closed_period_exceptions',
        'personal_access_tokens', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'migrations',
    ];

    /** @var array<string, list<string>>|null table => columns */
    private static ?array $columns = null;

    /**
     * @param  list<string>  $directColumns  e.g. ['customer_id']
     * @param  list<string>  $ignoreTables  tables owned by the record itself
     * @return array<string, int> "table.column" => rows pointing at the record, only the non-zero ones
     */
    public static function count(Model $record, array $directColumns, array $ignoreTables = []): array
    {
        $found = [];

        foreach (self::pointers($record, $directColumns, $ignoreTables) as [$table, $column, $typeColumn]) {
            $query = DB::table($table)->where($column, $record->getKey());

            if ($typeColumn !== null) {
                $query->where($typeColumn, $record->getMorphClass());
            }

            $rows = $query->count();

            if ($rows > 0) {
                $found["{$table}.{$column}"] = $rows;
            }
        }

        return $found;
    }

    /**
     * Every place a pointer at `$record` can live.
     *
     * @param  list<string>  $directColumns
     * @param  list<string>  $ignoreTables
     * @return list<array{0: string, 1: string, 2: ?string}> [table, id column, type column or null]
     */
    public static function pointers(Model $record, array $directColumns, array $ignoreTables = []): array
    {
        $skip = array_merge(self::HISTORY_TABLES, $ignoreTables, [$record->getTable()]);
        $pointers = [];

        foreach (self::columns() as $table => $columns) {
            if (in_array($table, $skip, true)) {
                continue;
            }

            foreach ($directColumns as $column) {
                if (in_array($column, $columns, true)) {
                    $pointers[] = [$table, $column, null];
                }
            }

            foreach ($columns as $column) {
                if (! str_ends_with($column, '_type')) {
                    continue;
                }

                $idColumn = substr($column, 0, -5).'_id';

                if (in_array($idColumn, $columns, true)) {
                    $pointers[] = [$table, $idColumn, $column];
                }
            }
        }

        return $pointers;
    }

    /** "3 sales orders, 2 sales invoices": what a sentence says about the counts. */
    public static function describe(array $counts): string
    {
        $parts = [];

        foreach ($counts as $where => $rows) {
            $table = explode('.', $where)[0];
            $parts[] = $rows.' '.str_replace('_', ' ', $table);
        }

        return implode(', ', array_slice($parts, 0, 6)).(count($parts) > 6 ? ' and more' : '');
    }

    /** @return array<string, list<string>> */
    private static function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        $tables = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $tables[$name] = Schema::getColumnListing($name);
        }

        return self::$columns = $tables;
    }

    /** Tests build a fresh schema per run; forget what was read. */
    public static function forget(): void
    {
        self::$columns = null;
    }
}
