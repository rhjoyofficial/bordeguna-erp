<?php

use Symfony\Component\Finder\Finder;

/**
 * ⚠⚠ WHY THIS FILE EXISTS.
 *
 * "No em dashes in anything written for Joy" is a standing rule, and it has
 * now RELAPSED THREE TIMES: swept from the inventory pages in 05e112f, sixteen
 * were back within days and swept again in 40f59d7, and the UI-redesign branch
 * put two hundred and fifty more in on the very day of that second sweep.
 * A cleanup commit does not fence off the future; a failing test does.
 *
 * ⚠ The ban is BLANKET over resources/js - strings and comments alike - because
 * a scanner that tried to decide "is this text Joy reads?" would be a second
 * implementation of the bundler and would rot exactly like the reachability
 * test's first version did. A hyphen costs nothing in a comment; the test
 * staying trivial is worth more than the distinction.
 */
it('keeps the em dash out of the frontend source', function () {
    $offenders = [];

    $files = Finder::create()
        ->in(resource_path('js'))
        ->files()
        ->name(['*.ts', '*.tsx']);

    foreach ($files as $file) {
        if (str_contains($file->getContents(), "\u{2014}")) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'Em dash (U+2014) found in: '.implode(', ', $offenders).'. The house rule is a hyphen.'
    );
});
