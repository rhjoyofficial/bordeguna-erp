<?php

use Symfony\Component\Finder\Finder;

/**
 * ⚠⚠ THE DURABLE PROTECTION FOR THE WHOLE LEDGER.
 *
 * Joy's standing rule, written for Inventory and applying with more force here:
 * "Everything that changes inventory quantity or value must go through the same
 * movement ledger and approval pipeline. I never want multiple code paths that
 * can modify inventory independently."
 *
 * The General Ledger is the one every other ledger will eventually report into,
 * so a second way to write a journal line would not merely be untidy - it would
 * be a set of books that cannot be reconciled to itself.
 *
 * ⚠ This family of test has already caught two REAL defects on this project: a
 * queued job that set the tenant without its permissions (W1), and
 * StockController::storeAdjustment calling the adjustment action directly while
 * recording no decision. Both were found by a scan exactly like this one, and
 * neither was found by a behavioural test.
 *
 * ⚠ Verified by reintroducing the defect. A structural test that has never
 * failed is a comment with a longer runtime.
 */

/**
 * ⚠⚠ Strips comments before scanning, and it is not a nicety.
 *
 * The first version of this file failed against ImmutableJournalException, whose
 * DOCBLOCK contains the words `$journal->update(...)` while explaining why that
 * is forbidden. A structural test that reads prose reports the documentation of
 * a rule as a breach of it, and the natural response is to weaken the test.
 */
function accountingSourceWithoutComments(string $contents): string
{
    $stripped = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $stripped .= "\n";

            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

/**
 * @param  list<string>  $allowed
 * @param  list<string>  $patterns
 * @return list<string>
 */
function accountingStructuralOffenders(array $allowed, array $patterns, string $verb): array
{
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $contents = accountingSourceWithoutComments($file->getContents());

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents, $matches)) {
                $offenders[] = "app/{$relative} {$verb}: ".trim($matches[0]);
            }
        }
    }

    return $offenders;
}

it('lets nothing but PostJournal write to the ledger', function () {
    // Where writing is legitimate: the action itself, and the two model files
    // that define the classes being written.
    $allowed = [
        'Modules/Accounting/Actions/PostJournal.php',
        'Modules/Accounting/Models/Journal.php',
        'Modules/Accounting/Models/JournalLine.php',
    ];

    $offenders = accountingStructuralOffenders($allowed, [
        // Constructing one at all. PostJournal uses `new JournalLine`, so this
        // is the pattern that would actually catch a second implementation
        // written in the same house style.
        '/new\s+(Journal|JournalLine)\s*[;(]/',
        // Eloquent creation, however it is spelled.
        '/\b(Journal|JournalLine)::(create|insert|forceCreate|firstOrCreate|updateOrCreate|upsert)\s*\(/',
        // A relation write on something that is plainly a journal.
        // ⚠ NOT a bare `->lines()->create(`: purchase orders, payslips and
        // salary structures all have a `lines()` relation of their own, and the
        // first version of this test reported six false positives across two
        // modules. A structural test that cries wolf gets deleted.
        '/\$\w*[Jj]ournal\w*\s*->\s*lines\s*\(\s*\)\s*->\s*(create|createMany|insert|save|saveMany)\s*\(/',
        // Going under Eloquent entirely.
        '/DB::table\s*\(\s*[\'"]journal(s|_lines)[\'"]\s*\)\s*->\s*(insert|update|delete|upsert)/',
    ], 'writes the ledger directly');

    expect($offenders)->toBeEmpty(
        "Only PostJournal may write to the ledger. Offenders:\n".implode("\n", $offenders)
    );
});

/**
 * ⚠⚠ THE TYPE MUST NEVER CHANGE THE MECHANISM.
 *
 * `JournalType` says why an entry exists; the date, the accounts and the
 * amounts say what happens. The moment the engine asks what kind of journal it
 * is posting, adding a third kind means editing the engine, and the claim that
 * "every journal is treated identically" quietly stops being true.
 *
 * This is the rule that has already survived a genuinely new seventh case in
 * InventoryAdjustmentType without a single line of change to the engine that
 * consumes it - the one real test of a claim otherwise made only in principle.
 * Two comments in this module assert this test exists; it does.
 */
it('never lets the posting engine ask what kind of journal it is', function () {
    $engine = ['Modules/Accounting/Actions/PostJournal.php', 'Modules/Accounting/Actions/ReverseJournal.php'];

    $offenders = [];

    foreach ($engine as $relative) {
        $contents = accountingSourceWithoutComments(file_get_contents(app_path($relative)));

        // A `match` anywhere in the engine, or any branch on the type at all.
        if (preg_match('/\bmatch\s*\(/', $contents, $m)) {
            $offenders[] = "app/{$relative} branches with match: {$m[0]}";
        }

        if (preg_match('/(if|elseif|\?\?|\?)\s*[^;]{0,80}(journal_type|JournalType::)/', $contents, $m)) {
            $offenders[] = "app/{$relative} branches on the journal type: ".trim($m[0]);
        }
    }

    expect($offenders)->toBeEmpty(
        "The type says why, not what happens. Offenders:\n".implode("\n", $offenders)
    );
});

/**
 * ⚠ The second half of the same rule. A journal can be written only by
 * PostJournal, and once written it can be changed by nobody at all - so a
 * correction has to be a new, opposite entry, which is what leaves both the
 * mistake and the fix visible.
 */
it('lets nothing update or delete a posted journal', function () {
    $allowed = [
        'Modules/Accounting/Models/Journal.php',
        'Modules/Accounting/Models/JournalLine.php',
    ];

    $offenders = accountingStructuralOffenders($allowed, [
        '/\b(Journal|JournalLine)::(query\(\))?\s*(->\s*\w+\([^)]*\)\s*)*->\s*(update|delete|forceDelete|truncate)\s*\(/',
        '/\$\w*[Jj]ournal\w*\s*->\s*(update|delete|forceDelete)\s*\(/',
    ], 'mutates a journal');

    expect($offenders)->toBeEmpty(
        "A posted journal is corrected by reversing it, never by editing it. Offenders:\n".implode("\n", $offenders)
    );
});
