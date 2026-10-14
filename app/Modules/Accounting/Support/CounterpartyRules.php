<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;

/**
 * Which counterparty may sit on which loan head (loans plan v2, phase 1; the
 * ChatGPT confirmation and the owner's answers of 14 Oct 2026).
 *
 * PartyLedgers says a loan line must NAME a counterparty. This says whether the
 * one it names is allowed there:
 *
 *  - 11752 Loans to Related Parties holds only a counterparty marked related,
 *    and 11751 Loans to Other Parties never holds one: a related-party loan is
 *    a disclosure, and one filed on 11751 would disappear from it.
 *  - None of the group's own businesses, on any loan head. Money between them
 *    moves by Inter-business transfers (1195/2190) and cancels across the group.
 *  - A deactivated counterparty takes no new posting.
 *
 * ⚠ Checked in PostJournal for every writer, and again on the voucher and
 * journal screens so the refusal lands on the line's own picker. A REVERSAL is
 * exempt: it undoes exactly what was posted, whatever has changed since.
 */
final class CounterpartyRules
{
    public const RELATED_ONLY = 'loans_to_related_parties';

    public const UNRELATED_ONLY = 'loans_to_other_parties';

    /** Why this counterparty cannot sit on this head, or null when it can. */
    public static function refusal(Account $account, Counterparty $counterparty): ?string
    {
        if (PartyLedgers::partyClassFor($account) !== Counterparty::class) {
            return null;
        }

        $name = $counterparty->legal_name;

        if (! $counterparty->is_active) {
            return "{$name} is deactivated. Reactivate it in Accounting > Counterparties before anything more is posted to it.";
        }

        if (($business = CounterpartyIdentity::ownBusinessNamed($name)) !== null) {
            return CounterpartyIdentity::ownBusinessRefusal($name, $business);
        }

        if ($account->system_slug === self::RELATED_ONLY && ! $counterparty->related_party) {
            $other = Account::bySlug(self::UNRELATED_ONLY)?->label() ?? 'Loans to Other Parties';

            return "{$account->label()} is only for a related party, and {$name} is not marked related. Use {$other} for it, or mark it related (and say how) in Accounting > Counterparties first.";
        }

        if ($account->system_slug === self::UNRELATED_ONLY && $counterparty->related_party) {
            $related = Account::bySlug(self::RELATED_ONLY)?->label() ?? 'Loans to Related Parties';
            $how = $counterparty->relationship === null ? '' : " ({$counterparty->relationship})";

            return "{$name} is a related party{$how}, so a loan to it goes on {$related}, not {$account->label()}.";
        }

        return null;
    }

    /**
     * Why the related-party mark cannot change now, or null when it can: the
     * head the balance sits on would no longer fit the counterparty. Read across
     * EVERY business, since the counterparty is group-wide.
     */
    public static function flagChangeRefusal(Counterparty $counterparty, bool $related): ?string
    {
        $stranded = $related ? self::UNRELATED_ONLY : self::RELATED_ONLY;
        $account = Account::bySlug($stranded);
        $balance = CounterpartyBalances::of($counterparty, everyBusiness: true)[$stranded] ?? 0;

        if ($account === null || $balance === 0) {
            return null;
        }

        $target = Account::bySlug($related ? self::RELATED_ONLY : self::UNRELATED_ONLY)?->label();
        $amount = Money::ofMinor($balance)->absolute()->format();

        return "{$counterparty->legal_name} still has {$amount} on {$account->label()}. Move it to {$target} with a journal voucher first, then change the related-party mark.";
    }

    /**
     * The note a picker shows under a loan head, as a key the screen
     * translates: `owner_loan`, `related_only`, `not_related`, `loan`, or null
     * for a head that is not a loan head.
     */
    public static function guidanceKey(Account $account): ?string
    {
        if (PartyLedgers::partyClassFor($account) !== Counterparty::class) {
            return null;
        }

        return match ($account->system_slug) {
            'owner_loans' => 'owner_loan',
            self::RELATED_ONLY => 'related_only',
            self::UNRELATED_ONLY => 'not_related',
            default => 'loan',
        };
    }
}
