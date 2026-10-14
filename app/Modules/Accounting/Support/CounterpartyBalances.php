<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each counterparty owes, or is owed, on each loan head: straight from the
 * party-tagged journal lines, never stored.
 *
 * ⚠ ONE QUERY, the same one for the list, the counterparty's page, the balance
 * confirmation letter and the reconciliation, so the four cannot disagree. A
 * balance is signed the ledger's way (debit positive): on an asset head a
 * debit is owed TO the business, on a liability head a credit is owed BY it.
 *
 * ⚠ THIS BUSINESS's books by default (journal lines carry their business). The
 * counterparty is group-wide; its balances are not. `everyBusiness` lifts the
 * tenant scope, and only the guards that must see the whole group use it
 * (deactivating, changing the related-party mark).
 */
final class CounterpartyBalances
{
    /**
     * The loan heads that exist in this chart, keyed by slug, in statement order.
     *
     * @return Collection<string, Account>
     */
    public static function heads(): Collection
    {
        $accounts = Account::query()->whereIn('system_slug', PartyLedgers::COUNTERPARTY_SLUGS)->get()->keyBy('system_slug');

        return collect(PartyLedgers::COUNTERPARTY_SLUGS)
            ->mapWithKeys(fn (string $slug) => [$slug => $accounts->get($slug)])
            ->filter();
    }

    /**
     * counterparty id => slug => signed balance in paisa. Heads with no lines
     * are absent, and so are counterparties with none.
     *
     * @param  list<int>|null  $counterpartyIds  null for every counterparty
     * @return array<int, array<string, int>>
     */
    public static function minor(?Carbon $asAt = null, ?array $counterpartyIds = null, bool $everyBusiness = false): array
    {
        $slugs = self::heads()->mapWithKeys(fn (Account $account, string $slug) => [$account->id => $slug]);

        if ($slugs->isEmpty() || $counterpartyIds === []) {
            return [];
        }

        $rows = ($everyBusiness ? JournalLine::withoutGlobalScopes() : JournalLine::query())
            ->whereIn('journal_lines.account_id', $slugs->keys()->all())
            ->where('journal_lines.party_type', (new Counterparty)->getMorphClass())
            ->when($counterpartyIds !== null, fn ($query) => $query->whereIn('journal_lines.party_id', $counterpartyIds))
            ->when($asAt !== null, fn ($query) => $query
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journals.journal_date', '<=', $asAt->toDateString()))
            ->groupBy('journal_lines.party_id', 'journal_lines.account_id')
            ->selectRaw('journal_lines.party_id as party_id, journal_lines.account_id as account_id, SUM(journal_lines.amount_minor) as total_minor')
            ->toBase()
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->party_id][$slugs[(int) $row->account_id]] = (int) $row->total_minor;
        }

        return $balances;
    }

    /**
     * One counterparty's non-zero balances in EVERY business, business by
     * business and head by head: what the deactivation guard names.
     *
     * @return list<array{business_id: int, slug: string, account: Account, minor: int}>
     */
    public static function acrossBusinesses(Counterparty $counterparty): array
    {
        $heads = self::heads();
        $slugs = $heads->mapWithKeys(fn (Account $account, string $slug) => [$account->id => $slug]);

        if ($slugs->isEmpty()) {
            return [];
        }

        $rows = JournalLine::withoutGlobalScopes()
            ->whereIn('account_id', $slugs->keys()->all())
            ->where('party_type', $counterparty->getMorphClass())
            ->where('party_id', $counterparty->getKey())
            ->groupBy('business_id', 'account_id')
            ->selectRaw('business_id, account_id, SUM(amount_minor) as total_minor')
            ->toBase()
            ->get();

        $order = array_flip(PartyLedgers::COUNTERPARTY_SLUGS);

        return $rows
            ->filter(fn ($row) => (int) $row->total_minor !== 0)
            ->map(fn ($row) => [
                'business_id' => (int) $row->business_id,
                'slug' => $slugs[(int) $row->account_id],
                'account' => $heads[$slugs[(int) $row->account_id]],
                'minor' => (int) $row->total_minor,
            ])
            ->sortBy(fn (array $row) => sprintf('%010d-%03d', $row['business_id'], $order[$row['slug']]))
            ->values()
            ->all();
    }

    /**
     * One counterparty's non-zero balances, in statement order.
     *
     * @return array<string, int> slug => signed paisa
     */
    public static function of(Counterparty $counterparty, ?Carbon $asAt = null, bool $everyBusiness = false): array
    {
        $mine = self::minor($asAt, [$counterparty->id], $everyBusiness)[$counterparty->id] ?? [];

        return array_filter(
            array_replace(array_intersect_key(array_flip(PartyLedgers::COUNTERPARTY_SLUGS), $mine), $mine),
            fn (int $minor) => $minor !== 0,
        );
    }
}
