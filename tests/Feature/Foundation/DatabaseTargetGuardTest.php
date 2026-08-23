<?php

use App\Modules\Foundation\Support\DatabaseTargetGuard;
use Illuminate\Support\Facades\DB;

/**
 * The guard written after the 2026-08-23 incident: a migration reached
 * production because `-e DB_DATABASE=scratch_ai` was ignored by a cached
 * config, and nothing anywhere compared the intention with reality.
 *
 * ⚠ These tests plant the mismatch rather than trusting the guard's shape. A
 * guard that cannot be shown to scream is a comment.
 *
 * ⚠⚠ The intended database is PASSED IN, never installed with `putenv`. The
 * first draft of this file did exactly that and the unset in its teardown
 * leaked across a parallel worker, emptying its SQLite database and failing
 * dozens of unrelated tests. Mutating process-global state to test a guard
 * costs more than the guard saves.
 */
it('throws when the intended database is not the one connected', function () {
    $live = DB::connection()->getDatabaseName();

    expect(fn () => DatabaseTargetGuard::assertConnectedToIntendedDatabase('scratch_ai'))
        ->toThrow(RuntimeException::class);

    // The message has to name both sides, or it does not help at 3am.
    try {
        DatabaseTargetGuard::assertConnectedToIntendedDatabase('scratch_ai');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('scratch_ai')
            ->and($e->getMessage())->toContain((string) $live)
            ->and($e->getMessage())->toContain('config');
    }
});

it('stays silent when the intended database is the one connected', function () {
    DatabaseTargetGuard::assertConnectedToIntendedDatabase(DB::connection()->getDatabaseName());
})->throwsNoExceptions();

it('stays silent when no intention is stated', function () {
    DatabaseTargetGuard::assertConnectedToIntendedDatabase('');
})->throwsNoExceptions();
