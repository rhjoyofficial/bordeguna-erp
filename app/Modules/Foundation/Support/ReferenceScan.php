<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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

    /**
     * ⭐ B24 (24 Oct 2026, R113 / Q5 = A): what uses a master record (a
     * product, brand, unit, warehouse...). The same scan as `count`, plus every
     * column whose FOREIGN KEY points at the record's table, so a pointer named
     * differently (`content_unit_id`, `component_product_id`, a category's
     * `parent_id`) still counts. Self references count too: a category with a
     * sub-category is in use; only the row itself is skipped.
     *
     * @param  list<string>  $directColumns
     * @param  list<string>  $ignoreTables  setup rows the record owns (its photos)
     * @return array<string, int>
     */
    public static function usage(Model $record, array $directColumns, array $ignoreTables = []): array
    {
        return self::usageMany($record, [(int) $record->getKey()], $directColumns, $ignoreTables)[(int) $record->getKey()] ?? [];
    }

    /**
     * The same for a whole list page at once: ONE grouped query per pointer,
     * never one per row (a list of 200 products stays a few dozen queries).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $directColumns
     * @param  list<string>  $ignoreTables
     * @return array<int, array<string, int>> id => "table.column" => rows (non-zero only)
     */
    public static function usageMany(Model $prototype, array $ids, array $directColumns, array $ignoreTables = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = array_fill_keys($ids, []);

        if ($ids === []) {
            return $found;
        }

        $own = $prototype->getTable();
        $pointers = [];

        foreach (self::pointers($prototype, $directColumns, $ignoreTables) as [$table, $column, $typeColumn]) {
            $pointers["{$table}.{$column}"] = [$table, $column, $typeColumn];
        }

        $skip = array_merge(self::HISTORY_TABLES, $ignoreTables);

        foreach (self::foreignKeys() as [$table, $column, $target]) {
            if ($target === $own && ! in_array($table, $skip, true)) {
                $pointers["{$table}.{$column}"] ??= [$table, $column, null];
            }
        }

        foreach ($pointers as $where => [$table, $column, $typeColumn]) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $query = DB::table($table)->whereIn($column, $chunk);

                if ($typeColumn !== null) {
                    $query->where($typeColumn, $prototype->getMorphClass());
                }

                // A row never uses itself (a self-referencing parent column).
                if ($table === $own) {
                    $query->whereColumn($column, '!=', $prototype->getKeyName());
                }

                foreach ($query->groupBy($column)->selectRaw("{$column} as ref, count(*) as n")->pluck('n', 'ref') as $id => $rows) {
                    if ((int) $rows > 0 && array_key_exists((int) $id, $found)) {
                        $found[(int) $id][$where] = (int) $rows;
                    }
                }
            }
        }

        return $found;
    }

    /** @var list<array{0: string, 1: string, 2: string}>|null [table, column, referenced table] */
    private static ?array $foreignKeys = null;

    /**
     * Every single-column foreign key in the schema. ⚠ Reading them asks the
     * database once per table, so the answer is cached, keyed by the newest
     * migration: a deploy that changes the schema reads it afresh.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function foreignKeys(): array
    {
        if (self::$foreignKeys !== null) {
            return self::$foreignKeys;
        }

        $read = function (): array {
            $keys = [];

            foreach (array_keys(self::columns()) as $table) {
                foreach (Schema::getForeignKeys($table) as $key) {
                    if (count($key['columns']) === 1) {
                        $keys[] = [$table, $key['columns'][0], $key['foreign_table']];
                    }
                }
            }

            return $keys;
        };

        return self::$foreignKeys = self::cached('fks', $read);
    }

    /**
     * ⭐ B24: the schema is read once per deploy, not once per request (a list
     * page asks for every row's usage). Keyed by the newest migration, so a
     * deploy that changes the schema reads it afresh. Tests always read live.
     */
    private static function cached(string $what, callable $read): array
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('migrations')) {
            return $read();
        }

        $stamp = DB::table('migrations')->max('id').'-'.DB::table('migrations')->count();

        return Cache::rememberForever("reference_scan.{$what}.{$stamp}", $read);
    }

    /** @return array<string, list<string>> */
    private static function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        return self::$columns = self::cached('columns', function (): array {
            $tables = [];

            foreach (Schema::getTables() as $table) {
                $name = $table['name'];

                if (str_starts_with($name, 'sqlite_')) {
                    continue;
                }

                $tables[$name] = Schema::getColumnListing($name);
            }

            return $tables;
        });
    }

    /** Tests build a fresh schema per run; forget what was read. */
    public static function forget(): void
    {
        self::$columns = null;
        self::$foreignKeys = null;
    }
}
