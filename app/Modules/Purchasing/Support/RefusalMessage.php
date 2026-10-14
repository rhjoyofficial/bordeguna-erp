<?php

namespace App\Modules\Purchasing\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The sentence a Purchasing screen may show for a refusal it caught.
 *
 * ⚠⚠ PUR-009 (audit 14 Oct 2026): the PO, receipt, return and bill screens
 * catch RuntimeException and show its message. A database error IS a
 * RuntimeException (QueryException extends PDOException), so a constraint
 * violation, for example two people saving the same bill number in the same
 * second, put the whole SQL statement with its bindings under the form. Now:
 *
 *  - a unique-index refusal becomes a sentence (the other save won);
 *  - any other database error is thrown on to the normal error page, never
 *    printed;
 *  - every other refusal keeps its own message, which is written for people.
 */
final class RefusalMessage
{
    public static function of(Throwable $e): string
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return 'Somebody saved the same document a moment ago, so this one was not saved. Reload the page and check before saving again.';
        }

        if ($e instanceof QueryException) {
            throw $e;
        }

        return $e->getMessage();
    }
}
