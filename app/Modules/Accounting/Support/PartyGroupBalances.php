<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ TALLY'S SUNDRY CREDITORS AND SUNDRY DEBTORS GROUPS (party ledgers, 20 Oct
 * 2026): every supplier or customer as one ledger row with its opening,
 * debits, credits and closing for the period, a click away from their ledger
 * view, which is a click away from each document.
 *
 *   Sundry Creditors  each supplier on 2110 Accounts Payable + 1160 Advances
 *   Sundry Debtors    each customer on 1140 Accounts Receivable + 2150 Advances
 *
 * Advances are in because in Tally they are on the party's own ledger (an
 * Advance reference). GRNI is not: Tally has no such account, and the supplier
 * ledger view shows it on its own.
 *
 * ⚠⚠ TIES OUT: the group total equals the control accounts' own balance; any
 * line on them naming nobody is shown as the difference, never hidden.
 */
final class PartyGroupBalances
{
    public const CREDITORS = 'creditors';

    public const DEBTORS = 'debtors';

    /** @return array<string, mixed> */
    public static function for(string $group, Carbon $from, Carbon $to, bool $showAll = false): array
    {
        $creditors = $group === self::CREDITORS;
        $class = $creditors ? Supplier::class : Customer::class;
        $accounts = self::accounts($group, $to);
        $ids = array_map(fn (Account $account) => $account->id, $accounts);
        $morph = (new $class)->getMorphClass();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $base = fn () => JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereIn('journal_lines.account_id', $ids);

        $byParty = fn (callable $narrow) => $narrow($base()->where('journal_lines.party_type', $morph))
            ->groupBy('journal_lines.party_id')
            ->selectRaw('journal_lines.party_id, SUM(journal_lines.amount_minor) as minor')
            ->pluck('minor', 'journal_lines.party_id')
            ->map(fn ($minor) => (int) $minor);

        $opening = $byParty(fn ($q) => $q->whereDate('journals.journal_date', '<', $fromDate));
        $within = fn ($q) => $q->whereDate('journals.journal_date', '>=', $fromDate)->whereDate('journals.journal_date', '<=', $toDate);
        $debits = $byParty(fn ($q) => $within($q)->where('journal_lines.amount_minor', '>', 0));
        $credits = $byParty(fn ($q) => $within($q)->where('journal_lines.amount_minor', '<', 0));

        $parties = $class::query()->orderBy('name')->get(['id', 'name', 'code', 'account_id']);
        $totals = ['opening' => 0, 'debits' => 0, 'credits' => 0, 'closing' => 0];
        $rows = [];

        foreach ($parties as $party) {
            $o = (int) ($opening[$party->id] ?? 0);
            $d = (int) ($debits[$party->id] ?? 0);
            $c = (int) ($credits[$party->id] ?? 0);
            $close = $o + $d + $c;

            $totals['opening'] += $o;
            $totals['debits'] += $d;
            $totals['credits'] += $c;
            $totals['closing'] += $close;

            if (! $showAll && $o === 0 && $d === 0 && $c === 0) {
                continue;
            }

            $rows[] = [
                'id' => $party->id,
                'name' => $party->name,
                'code' => $party->code,
                'opening' => self::signed($o),
                'debits' => Money::ofMinor($d)->format(),
                'credits' => Money::ofMinor($c)->absolute()->format(),
                'closing' => self::signed($close),
                // Drill down: the party's own head in the General Ledger opens their ledger view.
                'url' => $party->account_id === null ? null : self::url($party->account_id, $fromDate, $toDate),
            ];
        }

        // The independent check: the control accounts' whole balance, party or not.
        $ledger = (int) $base()->whereDate('journals.journal_date', '<=', $toDate)->sum('journal_lines.amount_minor');
        $unnamed = $ledger - $totals['closing'];

        return [
            'group' => $group,
            'from' => $fromDate,
            'to' => $toDate,
            'accounts' => array_map(fn (Account $account) => $account->label(), $accounts),
            'rows' => $rows,
            'total' => [
                'opening' => self::signed($totals['opening']),
                'debits' => Money::ofMinor($totals['debits'])->format(),
                'credits' => Money::ofMinor($totals['credits'])->absolute()->format(),
                'closing' => self::signed($totals['closing']),
            ],
            'ledger_closing' => self::signed($ledger),
            'unnamed' => $unnamed === 0 ? null : Money::ofMinor($unnamed)->absolute()->format(),
            'consistent' => $unnamed === 0,
            'show_all' => $showAll,
        ];
    }

    /** @return list<Account> */
    private static function accounts(string $group, Carbon $on): array
    {
        $pair = $group === self::CREDITORS
            ? [AccountMapping::forKeyOn('purchasing.accounts_payable', $on)?->account ?? Account::bySlug('accounts_payable'), Account::bySlug('supplier_advances')]
            : [AccountMapping::forKeyOn('sales.accounts_receivable', $on)?->account ?? Account::bySlug('accounts_receivable'), Account::bySlug('customer_advances')];

        return array_values(array_filter($pair));
    }

    private static function url(int $head, string $from, string $to): ?string
    {
        return Route::has('accounting.general-ledger') ? route('accounting.general-ledger', ['account' => $head, 'from' => $from, 'to' => $to], false) : null;
    }

    /** "1,200.00 Dr" or "300.00 Cr", never a bare negative. */
    private static function signed(int $minor): string
    {
        $money = Money::ofMinor($minor);

        return $money->absolute()->format().($money->isZero() ? '' : ($money->isCredit() ? ' Cr' : ' Dr'));
    }
}
