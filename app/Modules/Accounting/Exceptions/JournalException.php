<?php

namespace App\Modules\Accounting\Exceptions;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use RuntimeException;

/**
 * Every way a journal can be refused.
 *
 * ⚠ One exception class with named constructors rather than eight classes: the
 * caller almost always wants "this journal was refused, and here is the sentence
 * to show", and the specific reason is in the message. The one distinction that
 * mattered enough to be its own class is immutability, because that one is a
 * programming error rather than a user error.
 */
class JournalException extends RuntimeException
{
    public static function unbalanced(Money $difference): self
    {
        return new self(
            "This journal does not balance. Debits and credits differ by {$difference->absolute()->format()}."
        );
    }

    /**
     * 14 Oct 2026 (audit #8): the gap is too big to be rounding, so it is a
     * missing or wrong line, and booking it to Rounding Differences would hide it.
     */
    public static function roundingTooLarge(Money $difference, string $memo): self
    {
        return new self(
            "This entry is out by {$difference->absolute()->format()}, which is too large to be rounding ({$memo}). Nothing was saved. Check the amounts, or tell the administrator: a line is missing or wrong."
        );
    }

    public static function tooFewLines(): self
    {
        return new self('A journal needs at least two lines. One line can only balance if it is zero, and a zero journal records nothing.');
    }

    public static function zeroLine(int $lineNo): self
    {
        return new self("Line {$lineNo} is zero. A line that moves nothing does not belong in the ledger.");
    }

    public static function notPostable(Account $account): self
    {
        return new self("{$account->label()} is a heading, not an account you can post to. Choose one of the accounts under it.");
    }

    public static function partyRequired(Account $account, string $noun, int $lineNo): self
    {
        return new self("Line {$lineNo}: {$account->label()} keeps one ledger per {$noun}, so every posting there names the {$noun}. This one names nobody.");
    }

    public static function courierRequired(Account $account, int $lineNo): self
    {
        return new self("Line {$lineNo}: {$account->label()} keeps one balance per courier, so every posting there names the courier's money account. This one names none.");
    }

    public static function inactiveAccount(Account $account): self
    {
        return new self("{$account->label()} has been deactivated and cannot be posted to.");
    }

    public static function futureDate(string $date): self
    {
        return new self("A journal cannot be dated {$date}, which is in the future.");
    }

    public static function noBusiness(): self
    {
        return new self('A journal must belong to a business, and no business is selected.');
    }

    public static function foreignAccount(): self
    {
        return new self('That money account belongs to a different business.');
    }

    public static function moneyHeadWithoutAccount(Account $account): self
    {
        return new self("{$account->label()} is a cash, bank or wallet ledger with no Bank & cash account behind it. Add the account under Accounting > Bank & cash, which gives it its own ledger, and post through that.");
    }

    public static function moneyHeadOfAnotherBusiness(Account $account): self
    {
        return new self("{$account->label()} is a cash, bank or wallet ledger of another business.");
    }

    public static function moneyHeadShared(Account $account): self
    {
        return new self("{$account->label()} is shared by more than one Bank & cash account, so a plain line cannot say which one. Post through the account itself.");
    }

    public static function moneyHeadOfAnotherAccount(Account $account, string $moneyAccountName): self
    {
        return new self("{$account->label()} is not the ledger of {$moneyAccountName}. Money moves on its own account's ledger.");
    }

    public static function alreadyReversed(string $journalNumber): self
    {
        return new self("{$journalNumber} has already been reversed. Post a new journal rather than reversing it twice.");
    }

    public static function reversalOfReversal(string $journalNumber): self
    {
        return new self("{$journalNumber} is itself a reversal. Reversing it would make the question 'is this entry still live?' a chain to follow rather than a fact to read - post a fresh journal instead.");
    }
}
