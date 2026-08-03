<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;

/**
 * Everything that must be true of a set of books, checked out loud.
 *
 * Project convention since R9 and the third command of its kind, after
 * `inventory:verify-ledger`. Every subsidiary ledger ships with the command that
 * proves it.
 *
 * ⚠⚠ PROBLEMS AND WARNINGS ARE DIFFERENT THINGS. A problem means the books do
 * not add up and somebody must look now. A warning means something is worth a
 * human glance but may be perfectly correct - opening balances half entered, for
 * instance. Mixing them makes the command cry wolf, and a check nobody trusts is
 * a check nobody runs. The payroll engine's invisible-day finding taught the
 * same lesson.
 */
final class LedgerIntegrity
{
    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return array{problems: list<string>, warnings: list<string>, businesses: int, journals: int}
     */
    public static function verifyAllBusinesses(): array
    {
        $checker = new self;
        $businesses = 0;
        $journals = 0;

        // ⚠⚠ REMEMBER WHERE THE CALLER WAS, and put them back. Walking every
        // tenant is a side effect on global state, and finishing with
        // TenantScope::leave() would silently evict a caller that was inside a
        // business - a request, a job, a test - so the next query after this one
        // returns nothing and looks like missing data rather than a lost tenant.
        //
        // ⚠ Found by a test that ran the trial balance AFTER this method and got
        // an empty report. It is the same family as the three bugs where state
        // outlived its unit of work, seen from the other side: state destroyed
        // outside its unit of work.
        $callersBusiness = app(BusinessContext::class)->id();

        // ⚠⚠ RUNS COLD. A console command carries no BusinessContext, and every
        // model here is business-scoped, so without entering each tenant
        // BusinessScope compiles `1 = 0` and this reports "all consistent" while
        // examining NOTHING - the worst possible failure for an integrity check.
        // Fourth time this project has met the trap; it has its own test.
        foreach (Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            $businesses++;
            $journals += Journal::count();

            $checker->checkEveryJournalBalances($business->name);
            $checker->checkLinesBelongToTheirJournal($business->name);
            $checker->checkAccountsAreUsable($business->name);
            $checker->checkTheLedgerNetsToZero($business->name);
            $checker->checkOpeningBalanceEquity($business->name);
        }

        if ($callersBusiness === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($callersBusiness);
        }

        return [
            'problems' => $checker->problems,
            'warnings' => $checker->warnings,
            'businesses' => $businesses,
            'journals' => $journals,
        ];
    }

    /**
     * Level 1. Each journal, on its own, sums to zero.
     */
    private function checkEveryJournalBalances(string $business): void
    {
        $unbalanced = JournalLine::query()
            ->selectRaw('journal_id, SUM(amount_minor) as total')
            ->groupBy('journal_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->pluck('total', 'journal_id');

        foreach ($unbalanced as $journalId => $total) {
            $number = Journal::find($journalId)?->journal_number ?? "id {$journalId}";
            $out = Money::ofMinor((int) $total)->absolute()->format();

            $this->problems[] = "{$business}: journal {$number} does not balance, out by {$out}.";
        }

        // A journal with no lines at all is not caught above - it has nothing to
        // sum - and it is a journal number handed out for nothing.
        $empty = Journal::query()->doesntHave('lines')->pluck('journal_number');

        foreach ($empty as $number) {
            $this->problems[] = "{$business}: journal {$number} has no lines.";
        }
    }

    /**
     * Level 2. The denormalised `business_id` on a line agrees with its journal.
     *
     * ⚠ This is a REAL cross-check, not a formality. The line carries its own
     * copy so the trial balance need not join to find out whose it is, which is
     * exactly the shape that let a stock transfer leg be written against the
     * wrong warehouse and pass level 1 of inventory:verify-ledger.
     */
    private function checkLinesBelongToTheirJournal(string $business): void
    {
        $strays = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereColumn('journals.business_id', '<>', 'journal_lines.business_id')
            ->count();

        if ($strays > 0) {
            $this->problems[] = "{$business}: {$strays} journal line(s) are filed under a different business from their journal.";
        }
    }

    /**
     * Level 3. Every line points at an account that exists and can be posted to.
     *
     * ⚠ A MISSING account is a problem; an account that has since become a
     * heading or been deactivated is a WARNING. Both are legal moves after the
     * fact - an account can be retired once its history is complete - and
     * failing the whole command for one would train people to ignore it.
     *
     * ⚠⚠ The check my own design note asked for here, "no journal sits inside a
     * closed period", was DROPPED on reflection: closing July after posting
     * July's entries is the entire point of closing a period, so that check
     * would flag correct behaviour on every set of books that has ever closed a
     * month. Nothing in the data distinguishes "posted before the lock" from
     * "posted after it", because the lock's own history is not recorded per
     * journal. A noisy check is worse than no check.
     */
    private function checkAccountsAreUsable(string $business): void
    {
        $orphans = JournalLine::query()
            ->leftJoin('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereNull('accounts.id')
            ->count();

        if ($orphans > 0) {
            $this->problems[] = "{$business}: {$orphans} journal line(s) point at an account that no longer exists.";
        }

        $unpostable = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where(fn ($query) => $query->where('accounts.is_postable', false)->orWhere('accounts.is_active', false))
            ->distinct()
            ->pluck('accounts.code');

        foreach ($unpostable as $code) {
            $this->warnings[] = "{$business}: account {$code} carries postings but is now a heading or deactivated.";
        }
    }

    /**
     * Level 4. What a user is SHOWN nets to zero.
     *
     * ⚠⚠ Deliberately runs the SAME TrialBalance the screen renders, rather than
     * a second sum that happens to agree. This project has shipped a defect in a
     * presentation path twice; a verifier that checks its own arithmetic instead
     * of the report's checks the wrong thing.
     */
    private function checkTheLedgerNetsToZero(string $business): void
    {
        $earliest = Journal::query()->min('journal_date');

        if ($earliest === null) {
            return;
        }

        $trialBalance = TrialBalance::between(
            Carbon::parse($earliest)->startOfDay(),
            Carbon::today()->endOfDay(),
        );

        if (! $trialBalance->balances()) {
            $out = $trialBalance->difference()->absolute()->format();
            $this->problems[] = "{$business}: the trial balance does not balance, out by {$out}.";
        }
    }

    /**
     * ⚠ Opening Balance Equity is the self-check on the opening figures: once
     * every one is in, it must read zero. A non-zero balance is the arithmetic
     * saying a figure is missing or wrong, before it has polluted a year of
     * trading - a trial balance can balance perfectly and still be wrong, and
     * this is the only check that catches that.
     *
     * ⚠⚠ A WARNING, never a problem. Half-entered opening balances are a normal
     * state that can last days, and failing an integrity command for it would
     * mean the command is red for the whole of setup - which is precisely when
     * somebody needs to be able to trust it.
     */
    private function checkOpeningBalanceEquity(string $business): void
    {
        $openingJournals = Journal::ofType(JournalType::OpeningBalance)->count();

        if ($openingJournals === 0) {
            return;
        }

        $account = Account::bySlug('opening_balance_equity');

        if ($account === null) {
            $this->problems[] = "{$business}: no Opening Balance Equity account exists, so opening figures cannot be proved.";

            return;
        }

        $balance = Money::ofMinor((int) JournalLine::query()->where('account_id', $account->id)->sum('amount_minor'));

        if (! $balance->isZero()) {
            $this->warnings[] = "{$business}: Opening Balance Equity is {$balance->absolute()->format()}, not zero - an opening figure is missing or wrong.";
        }

        // Once the books are open, this account should never move again.
        $laterUse = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journals.journal_type', '<>', JournalType::OpeningBalance->value)
            ->count();

        if ($laterUse > 0) {
            $this->warnings[] = "{$business}: {$laterUse} line(s) post to Opening Balance Equity outside an opening balance entry.";
        }
    }
}
