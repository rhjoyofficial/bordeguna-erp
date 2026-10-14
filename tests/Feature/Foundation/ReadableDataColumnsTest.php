<?php

use App\Modules\Foundation\Support\Ai\ReadableData;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ 14 Oct 2026: seven columns the assistant was allowed to read did not
 * exist. SQLite turns an unknown double-quoted column into a string literal and
 * answers; MySQL refuses the whole query, so the assistant could read none of
 * products, stock, invoices, sales orders or purchase orders in production.
 * Every listed column must be a real one, checked on whichever database runs.
 */
it('lists only columns that exist, for every table the assistant may read', function () {
    $tables = (new ReflectionClassConstant(ReadableData::class, 'TABLES'))->getValue();

    $missing = [];
    foreach ($tables as $table => $spec) {
        foreach ([...$spec['columns'], ...(isset($spec['owner']) ? [$spec['owner']] : [])] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    expect($missing)->toBe([]);
});
