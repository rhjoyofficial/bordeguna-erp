<?php

use Symfony\Component\Finder\Finder;

/**
 * ⚠⚠ MYSQL REFUSES ANY IDENTIFIER OVER 64 CHARACTERS. SQLITE DOES NOT.
 *
 * On 2026-08-03 a migration created `supplier_payment_allocations` with an
 * unnamed two-column unique index. Laravel generates
 * `supplier_payment_allocations_supplier_payment_id_supplier_bill_id_unique`,
 * which is 71 characters. MySQL rejected it, the migration aborted having
 * ALREADY created both tables, and it left production with the tables present,
 * the index missing and the migration unrecorded - a half-applied state that
 * neither `migrate` nor `migrate:status` could resolve on its own.
 *
 * The entire suite was green throughout, because the suite runs on SQLite and
 * SQLite has no such limit. That is the fifth time this project has been caught
 * by something only the production driver enforces, and the previous four were
 * all found by a human noticing rather than by a test.
 *
 * ⚠ This test reads the SOURCE rather than the schema, deliberately. Asking the
 * database would only ever ask SQLite, which is the one that does not care.
 */
it('never lets a migration generate an index name MySQL will refuse', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->path('database/migrations')->name('*.php') as $file) {
        $contents = $file->getContents();

        // Track which table each block belongs to, so the generated name can be
        // reconstructed the way Laravel builds it: table_col1_col2_type.
        preg_match_all(
            '/Schema::(?:create|table)\(\s*[\'"](?<table>\w+)[\'"](?<body>.*?)(?=Schema::(?:create|table)\(|\z)/s',
            $contents,
            $blocks,
            PREG_SET_ORDER,
        );

        foreach ($blocks as $block) {
            $table = $block['table'];

            // Unnamed multi-column unique/index calls: `->unique(['a', 'b'])`
            // with no second argument. A named one is always safe.
            preg_match_all(
                '/->(?<type>unique|index)\(\s*\[(?<columns>[^\]]*)\]\s*\)/',
                $block['body'],
                $calls,
                PREG_SET_ORDER,
            );

            foreach ($calls as $call) {
                preg_match_all('/[\'"]([\w.]+)[\'"]/', $call['columns'], $columnMatches);

                $columns = array_map(
                    fn (string $column) => str_replace('.', '_', $column),
                    $columnMatches[1],
                );

                if ($columns === []) {
                    continue;
                }

                $generated = strtolower($table.'_'.implode('_', $columns).'_'.$call['type']);

                if (strlen($generated) > 64) {
                    $offenders[] = sprintf(
                        '%s: %s (%d chars) - give it an explicit short name.',
                        $file->getRelativePathname(),
                        $generated,
                        strlen($generated),
                    );
                }
            }
        }
    }

    expect($offenders)->toBeEmpty(
        "MySQL refuses identifiers over 64 characters and SQLite does not, so these pass the suite and fail the deploy:\n"
        .implode("\n", $offenders)
    );
});
