<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One account's postings for a period, with a running balance.
 *
 * The trial balance says an account moved by 118,400. This says WHICH postings
 * moved it, in the order they happened, and what the balance was after each one.
 * It is the drill-down every other accounting screen has been pointing at.
 *
 * ⚠⚠ THE OPENING AND CLOSING BALANCES COME FROM `TrialBalance` (C-GLA-1), never
 * from a second sum of this class's own. Two queries computing one figure
 * eventually disagree, and the one on the screen is the one people trust. It
 * also keeps `accounting:verify-ledger` level 4 covering the figures a user is
 * actually shown, which is why that level was added in the first place.
 *
 * ⚠ Business scoping comes from `JournalLine`'s own global scope, exactly as in
 * `TrialBalance`. This class never writes `where business_id`, so it cannot
 * disagree with the rest of the system about which tenant it is in.
 */
final class AccountLedger
{
    /**
     * @param  Collection<int, LedgerEntry>  $entries
     */
    private function __construct(
        public readonly Account $account,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Money $opening,
        public readonly Money $debits,
        public readonly Money $credits,
        public readonly Collection $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $partyType,
        public readonly ?int $partyId,
        /**
         * The trial balance's own closing figure for this account, kept SEPARATE
         * from the one this class derives.
         *
         * ⚠⚠ Keeping both is the entire point. If `closing()` were simply
         * `opening + movement`, C-GLA-4 would be comparing a number with itself
         * and would pass on a report that had silently dropped half its lines.
         * Null when a party filter is on, because no trial-balance row describes
         * one party's share of an account.
         */
        private readonly ?Money $trialBalanceClosing,
    ) {}

    /**
     * @param  positive-int  $perPage
     * @param  positive-int  $page
     */
    public static function for(
        Account $account,
        Carbon $from,
        Carbon $to,
        ?string $partyType = null,
        ?int $partyId = null,
        int $perPage = 100,
        int $page = 1,
    ): self {
        $filtered = $partyType !== null && $partyId !== null;

        // ⚠ C-GLA-7. A party-filtered view CANNOT take its opening from the
        // trial balance: that is the whole account's opening, and presenting it
        // above one party's lines is exactly the subledger control gap this
        // project has already been bitten by - a total drawn from the whole
        // account beside detail drawn from party-tagged lines only.
        if ($filtered) {
            $opening = self::sumOf(fn (Builder $query) => self::scoped($query, $account, $partyType, $partyId)
                ->whereDate('journals.journal_date', '<', $from->toDateString()));
            $trialBalanceClosing = null;
        } else {
            $row = TrialBalance::between($from, $to)->rows
                ->first(fn (TrialBalanceRow $candidate) => $candidate->account->id === $account->id);

            $opening = $row?->opening ?? Money::zero();
            $trialBalanceClosing = $row?->closing() ?? Money::zero();
        }

        $inWindow = fn (Builder $query) => self::scoped($query, $account, $partyType, $partyId)
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString());

        $debits = self::sumOf(fn (Builder $query) => $inWindow($query)->where('journal_lines.amount_minor', '>', 0));
        $credits = self::sumOf(fn (Builder $query) => $inWindow($query)->where('journal_lines.amount_minor', '<', 0));

        $total = $inWindow(JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id'))
            ->count('journal_lines.id');

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // ⚠⚠ C-GLA-3. The seed is the opening plus EVERY line before this page,
        // computed as one aggregate. Restarting the running balance from the
        // opening on page two is the classic defect here, and every figure on
        // that page would be wrong by a page's worth of postings.
        $balance = $opening->plus(self::sumBefore($account, $from, $to, $partyType, $partyId, $offset));

        $lines = self::ordered($inWindow(
            JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ))
            ->with(['journal', 'party'])
            ->select('journal_lines.*')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $entries = $lines->map(function (JournalLine $line) use (&$balance) {
            $balance = $balance->plus($line->amount());

            return new LedgerEntry($line, $balance);
        });

        return new self(
            account: $account,
            from: $from,
            to: $to,
            opening: $opening,
            debits: $debits,
            credits: $credits,
            entries: $entries,
            total: $total,
            page: $page,
            perPage: $perPage,
            partyType: $partyType,
            partyId: $partyId,
            trialBalanceClosing: $trialBalanceClosing,
        );
    }

    /**
     * Where the account stood at the end of the window.
     *
     * ⚠ Derived from the movement this report lists, NOT taken from the trial
     * balance, so that `isConsistent()` has two independent figures to compare.
     */
    public function closing(): Money
    {
        return $this->opening->plus($this->debits)->plus($this->credits);
    }

    /**
     * ⚠⚠ C-GLA-4. The listing's own arithmetic against the trial balance's,
     * exactly, in whole paisa.
     *
     * `opening + (debits + credits summed from the lines this report lists)`
     * must equal the closing balance the trial balance reports for the same
     * account and window. If they disagree, this report is missing postings the
     * trial balance can see, and the screen says so rather than showing a
     * plausible page of wrong numbers. A general ledger that quietly disagrees
     * with the trial balance above it is worse than no general ledger: it is
     * what makes people stop believing the correct reports too.
     *
     * ⚠ There is nothing to check against under a party filter (C-GLA-7): no
     * trial-balance row describes one party's share of an account. The screen
     * shows the check only when it means something, rather than showing a tick
     * that was never tested.
     */
    public function hasIndependentCheck(): bool
    {
        return $this->trialBalanceClosing !== null;
    }

    public function isConsistent(): bool
    {
        return $this->trialBalanceClosing === null
            || $this->closing()->minor === $this->trialBalanceClosing->minor;
    }

    public function trialBalanceClosing(): ?Money
    {
        return $this->trialBalanceClosing;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function isFilteredByParty(): bool
    {
        return $this->partyType !== null && $this->partyId !== null;
    }

    /**
     * Every party that has a posting on this account in the window, for the
     * filter dropdown.
     *
     * ⚠ Built from the postings themselves rather than from the customer or
     * supplier list. The filter can then only offer values that exist, which is
     * also what makes accepting one from the URL safe: nothing else is
     * resolvable.
     *
     * @return Collection<int, array{type: string, id: int, label: string}>
     */
    public static function partiesOn(Account $account, Carbon $from, Carbon $to): Collection
    {
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereNotNull('journal_lines.party_id')
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->select('journal_lines.party_type', 'journal_lines.party_id')
            ->distinct()
            ->get()
            ->load('party')
            ->map(function (JournalLine $line) {
                $party = $line->party;

                return [
                    'type' => class_basename($line->party_type),
                    'id' => (int) $line->party_id,
                    'label' => $party?->name ?? class_basename($line->party_type)." #{$line->party_id}",
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /**
     * Resolves a `Customer:12` filter against the parties that actually exist on
     * this account, and returns null for anything else.
     *
     * ⚠ Deliberately NOT a class_exists() lookup on a URL string. The only
     * acceptable inputs are values already stored in the ledger, so the
     * resolution is a membership test rather than a name resolution.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function resolveParty(?string $filter, Account $account, Carbon $from, Carbon $to): ?array
    {
        if ($filter === null || ! str_contains($filter, ':')) {
            return null;
        }

        [$basename, $id] = explode(':', $filter, 2);

        if (! ctype_digit($id)) {
            return null;
        }

        $type = JournalLine::query()
            ->where('account_id', $account->id)
            ->where('party_id', (int) $id)
            ->whereNotNull('party_type')
            ->pluck('party_type')
            ->first(fn (string $candidate) => class_basename($candidate) === $basename);

        return $type === null ? null : [$type, (int) $id];
    }

    // ─── Query machinery ────────────────────────────────────────────────────

    /**
     * ⚠⚠ C-GLA-2. A TOTAL ORDER, not just a date.
     *
     * Date alone is not an order: two journals on the same day come back in
     * whatever sequence the engine felt like, so two people opening the same
     * report would see two different running balances against the same rows.
     * That is the ordinary case as soon as a day has more than one posting, and
     * it is also what makes C-GLA-3's page seed meaningful - an offset into an
     * unstable order is an offset into nothing.
     */
    private static function ordered(Builder $query): Builder
    {
        return $query
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no');
    }

    private static function scoped(Builder $query, Account $account, ?string $partyType, ?int $partyId): Builder
    {
        $query->where('journal_lines.account_id', $account->id);

        if ($partyType !== null && $partyId !== null) {
            $query->where('journal_lines.party_type', $partyType)
                ->where('journal_lines.party_id', $partyId);
        }

        return $query;
    }

    /**
     * @param  callable(Builder): Builder  $narrow
     */
    private static function sumOf(callable $narrow): Money
    {
        $query = JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id');

        return Money::ofMinor((int) $narrow($query)->sum('journal_lines.amount_minor'));
    }

    /**
     * The movement contained in every line before `$offset` in the report order.
     *
     * ⚠ A derived table with a LIMIT rather than loading the earlier pages. The
     * seed for page 40 must not cost thirty-nine pages of hydrated models, and a
     * SUM over a LIMIT is the same arithmetic without the objects.
     */
    private static function sumBefore(
        Account $account,
        Carbon $from,
        Carbon $to,
        ?string $partyType,
        ?int $partyId,
        int $offset,
    ): Money {
        if ($offset <= 0) {
            return Money::zero();
        }

        $earlier = self::ordered(
            self::scoped(
                JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id'),
                $account,
                $partyType,
                $partyId,
            )
                ->whereDate('journals.journal_date', '>=', $from->toDateString())
                ->whereDate('journals.journal_date', '<=', $to->toDateString())
        )
            ->select('journal_lines.amount_minor')
            ->limit($offset);

        return Money::ofMinor(
            (int) DB::query()->fromSub($earlier->toBase(), 'earlier')->sum('amount_minor')
        );
    }
}
