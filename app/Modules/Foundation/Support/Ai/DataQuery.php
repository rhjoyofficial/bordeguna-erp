<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns the assistant's structured request into a query, and runs it read-only.
 *
 * ⚠⚠ THE MODEL NEVER WRITES SQL. It sends a table name, some column names, some
 * filters and a limit. This class builds the statement. That removes the entire
 * class of attack where a model is talked into a UNION against a forbidden
 * table, a nested subquery, a comment-terminated injection or INTO OUTFILE:
 * there is no SQL string coming from the model for anything to hide inside.
 *
 * ⚠⚠ SCOPING IS APPLIED HERE, NEVER BY THE MODEL. business_id is always added.
 * An owner-scoped table always gets the asker's id. The model cannot leave
 * either out, because it never supplies them - they are appended after its
 * request has been parsed and validated.
 *
 * ⚠ Runs on the `readonly` connection, whose MySQL user is granted SELECT and
 * nothing else. That is the last barrier rather than the first: if every check
 * above were bypassed at once, the database itself still refuses to write.
 */
class DataQuery
{
    /** ⚠ A hard ceiling the model cannot raise. A question that wants more rows
     * than this is a question that should have been an aggregate. */
    private const MAX_ROWS = 200;

    /** Operators a filter may use. Anything else is refused rather than guessed. */
    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'like', 'in', 'is null', 'is not null'];

    public function __construct(
        private readonly ReadableData $readable,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $request  what the model asked for
     * @return array{rows: array<int,mixed>, count: int, table: string, columns: list<string>}
     */
    public function run(User $user, array $request): array
    {
        $table = (string) ($request['table'] ?? '');

        // ⚠⚠ The permission check, and the reason an invented table name is
        // harmless. A table this person may not read is indistinguishable, from
        // here, from a table that does not exist.
        $spec = $this->readable->spec($user, $table);

        if ($spec === null) {
            throw new RuntimeException("There is no data called '{$table}' that you can see.");
        }

        $columns = $this->columns($request, $spec);

        $query = DB::connection(ReadOnlyConnection::name())->table($table)->select($columns);

        // ⚠ Always. Four businesses share this database and a question asked in
        // one must never reach another's rows.
        $query->where("{$table}.business_id", $this->context->id());

        // ⚠⚠ Personal stays personal. The owner is taken from the SESSION, never
        // from the request - there is deliberately no parameter for the model to
        // name somebody else in.
        if (isset($spec['owner'])) {
            $query->where("{$table}.{$spec['owner']}", $user->id);
        }

        $this->applyFilters($query, $table, $request['filters'] ?? [], $spec['columns']);

        $limit = min((int) ($request['limit'] ?? 50), self::MAX_ROWS);
        $rows = $query->limit(max($limit, 1))->get();

        return [
            'table' => $table,
            'columns' => $columns,
            'count' => $rows->count(),
            'rows' => $rows->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /**
     * ⚠⚠ COLUMNS ARE INTERSECTED WITH THE MAP, never taken as given. A column
     * the model names that is not listed for this table is dropped rather than
     * passed through - which is what stops `national_id` being read off a table
     * whose other columns are harmless.
     *
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $spec
     * @return list<string>
     */
    private function columns(array $request, array $spec): array
    {
        $asked = $request['columns'] ?? null;

        if (! is_array($asked) || $asked === []) {
            return $spec['columns'];
        }

        $allowed = array_values(array_intersect($asked, $spec['columns']));

        // Asking only for columns that do not exist is a mistake, not a licence
        // to return everything - but returning nothing is useless, so the
        // documented set is the fallback.
        return $allowed === [] ? $spec['columns'] : $allowed;
    }

    /**
     * @param  array<int,mixed>  $filters
     * @param  list<string>  $allowedColumns
     */
    private function applyFilters(Builder $query, string $table, array $filters, array $allowedColumns): void
    {
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $column = (string) ($filter['column'] ?? '');
            $operator = strtolower(trim((string) ($filter['operator'] ?? '=')));

            // ⚠ Both are checked against a list. A column outside the map, or an
            // operator outside the set, is DROPPED rather than interpreted -
            // there is no path here where an unrecognised string reaches SQL.
            if (! in_array($column, $allowedColumns, true) || ! in_array($operator, self::OPERATORS, true)) {
                continue;
            }

            $qualified = "{$table}.{$column}";

            match ($operator) {
                'is null' => $query->whereNull($qualified),
                'is not null' => $query->whereNotNull($qualified),
                'in' => $query->whereIn($qualified, array_slice((array) ($filter['value'] ?? []), 0, 50)),
                // ⚠ Bound as a parameter by the query builder, never
                // interpolated - the value is the one thing here that is
                // genuinely free text.
                default => $query->where($qualified, $operator, $filter['value'] ?? null),
            };
        }
    }
}
