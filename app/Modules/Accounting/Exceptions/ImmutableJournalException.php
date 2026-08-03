<?php

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * ⚠⚠ A posted journal is never edited and never deleted. A mistake is corrected
 * by posting the opposite entry, which leaves both the error and the correction
 * visible - and that visibility IS the audit trail, not a side effect of it.
 *
 * ⚠ This is a programming error rather than a user error, which is why it has
 * its own class: no screen should ever be able to reach it. It exists so that a
 * future `$journal->update(...)` written in good faith fails loudly on the first
 * run instead of silently rewriting a month that has already been reported.
 *
 * Same guarantee, same shape, as ImmutablePunchException in HRM.
 */
class ImmutableJournalException extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self('A posted journal cannot be changed. Reverse it and post a correcting entry.');
    }

    public static function forDelete(): self
    {
        return new self('A posted journal cannot be deleted. Reverse it and post a correcting entry.');
    }
}
