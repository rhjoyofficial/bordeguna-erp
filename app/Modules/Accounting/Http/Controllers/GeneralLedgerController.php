<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\AccountLedger;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\LedgerEntry;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drill-down every other accounting screen has been pointing at.
 *
 * The trial balance says Cost of Sales moved by 118,400 and the profit and loss
 * puts that figure under a heading. This says WHICH postings, in the order they
 * happened, with the balance after each one and a link to the journal behind it.
 *
 * ⚠ C-GLA-5: an account must be chosen. There is no "every account" mode,
 * because that report already exists and is called the journals list. A general
 * ledger without an account is a slower version of it with a running balance
 * that runs across unrelated accounts, which is a number with no meaning.
 */
class GeneralLedgerController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        $account = $request->filled('account')
            ? Account::query()->find($request->integer('account'))
            : null;

        // ⚠ The picker offers every postable account, not only the ones with
        // postings. "This account has nothing in it" is an answer somebody
        // opened the report to get, and an account missing from the list looks
        // like a system that has lost it.
        $accounts = Account::query()->active()->inChartOrder()->get()->map(fn (Account $option) => [
            'id' => $option->id,
            'label' => $option->label(),
            'type_label' => $option->type->label(),
            'postable' => (bool) $option->is_postable,
        ]);

        if ($account === null) {
            return Inertia::render('accounting/general-ledger/index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'accounts' => $accounts,
                'account' => null,
                'parties' => [],
                'party' => null,
                'entries' => [],
                'summary' => null,
                'pagination' => null,
                'ledger_empty' => Journal::count() === 0,
            ]);
        }

        // ⭐ 26 Sep 2026: a HEADING chosen here rolls up every head beneath it
        // for the window, one row per head with a link to its own ledger, and
        // a total. Headings can now be made by the accountant from a voucher
        // (ledger-heads plan D2), so "what did Field Costs come to" needs an
        // answer on this screen rather than a hunt through the heads.
        if (! $account->is_postable) {
            return Inertia::render('accounting/general-ledger/index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'accounts' => $accounts,
                'account' => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'label' => $account->label(),
                    'type_label' => $account->type->label(),
                ],
                'parties' => [],
                'party' => null,
                'entries' => [],
                'summary' => null,
                'pagination' => null,
                'rollup' => $this->rollup($account, $from, $to),
                'ledger_empty' => Journal::count() === 0,
            ]);
        }

        $parties = AccountLedger::partiesOn($account, $from, $to);
        $resolved = AccountLedger::resolveParty($request->string('party')->toString() ?: null, $account, $from, $to);

        $ledger = AccountLedger::for(
            account: $account,
            from: $from,
            to: $to,
            partyType: $resolved[0] ?? null,
            partyId: $resolved[1] ?? null,
            perPage: self::PER_PAGE,
            page: max(1, $request->integer('page', 1)),
        );

        return Inertia::render('accounting/general-ledger/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'accounts' => $accounts,
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'label' => $account->label(),
                'type_label' => $account->type->label(),
            ],
            'parties' => $parties,
            'party' => $resolved === null ? null : class_basename($resolved[0]).':'.$resolved[1],
            'entries' => $ledger->entries->map(fn (LedgerEntry $entry) => [
                'id' => $entry->line->id,
                'journal_id' => $entry->line->journal->id,
                'journal_number' => $entry->line->journal->journal_number,
                'date' => $entry->line->journal->journal_date->toDateString(),
                // ⭐ 12 Oct 2026: what the voucher says it was for, not the
                // poster's "account - party" line memo (see VoucherNarrations).
                'memo' => $entry->description(),
                'voucher' => ($voucher = $entry->voucher()) === null ? null : [
                    'id' => $voucher->id,
                    'number' => $voucher->voucher_number,
                ],
                'party' => $entry->partyName(),
                // ⚠ Every figure formatted explicitly. `19200.00000000` has
                // reached a real user on this project twice, in two different
                // modules, the second time because fixing an instance was
                // mistaken for fixing a pattern.
                'debit' => $entry->isDebit() ? $entry->amount()->format() : null,
                'credit' => $entry->isDebit() ? null : $entry->amount()->absolute()->format(),
                'balance' => $entry->balance->absolute()->format(),
                'balance_side' => $entry->balance->isCredit() ? 'credit' : 'debit',
            ])->values(),
            'summary' => [
                'opening' => $ledger->opening->absolute()->format(),
                'opening_side' => $ledger->opening->isCredit() ? 'credit' : 'debit',
                'debits' => $ledger->debits->format(),
                'credits' => $ledger->credits->absolute()->format(),
                'closing' => $ledger->closing()->absolute()->format(),
                'closing_side' => $ledger->closing()->isCredit() ? 'credit' : 'debit',
                // ⚠⚠ C-GLA-4. The screen states whether its own arithmetic
                // agrees with the trial balance, and shows the check only when
                // there was something to check against (C-GLA-7).
                'checked' => $ledger->hasIndependentCheck(),
                'consistent' => $ledger->isConsistent(),
                'trial_balance_closing' => $ledger->trialBalanceClosing()?->absolute()->format(),
                'party_filtered' => $ledger->isFilteredByParty(),
            ],
            'pagination' => [
                'page' => $ledger->page,
                'pages' => $ledger->pages(),
                'total' => $ledger->total,
            ],
            'ledger_empty' => Journal::count() === 0,
        ]);
    }

    /**
     * Every postable head under a heading, at any depth, with its movement in
     * the window and its balance at each end, plus the total. Read straight
     * from journal_lines like the trial balance, so the two always agree.
     *
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    private function rollup(Account $heading, Carbon $from, Carbon $to): array
    {
        $all = Account::query()->get(['id', 'code', 'name', 'parent_id', 'is_postable']);
        $under = [$heading->id => true];

        do {
            $before = count($under);
            foreach ($all as $row) {
                if ($row->parent_id !== null && isset($under[$row->parent_id])) {
                    $under[$row->id] = true;
                }
            }
        } while (count($under) > $before);

        unset($under[$heading->id]);
        $heads = $all->filter(fn ($row) => isset($under[$row->id]) && $row->is_postable)->sortBy('code')->values();
        $ids = $heads->pluck('id')->all();

        $sum = fn (callable $where) => JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereIn('journal_lines.account_id', $ids)
            ->where($where)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, sum(journal_lines.amount_minor) as minor')
            ->pluck('minor', 'journal_lines.account_id');

        $opening = $sum(fn ($q) => $q->where('journals.journal_date', '<', $from->toDateString()));
        $debits = $sum(fn ($q) => $q->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])->where('journal_lines.amount_minor', '>', 0));
        $credits = $sum(fn ($q) => $q->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])->where('journal_lines.amount_minor', '<', 0));

        $totals = ['opening' => 0, 'debits' => 0, 'credits' => 0, 'closing' => 0];
        $rows = [];

        foreach ($heads as $head) {
            $o = (int) ($opening[$head->id] ?? 0);
            $d = (int) ($debits[$head->id] ?? 0);
            $c = (int) ($credits[$head->id] ?? 0);
            $close = $o + $d + $c;

            $totals['opening'] += $o;
            $totals['debits'] += $d;
            $totals['credits'] += $c;
            $totals['closing'] += $close;

            $rows[] = [
                'id' => $head->id,
                'label' => $head->code.' - '.$head->name,
                'opening' => $this->signed($o),
                'debits' => Money::ofMinor($d)->format(),
                'credits' => Money::ofMinor($c)->absolute()->format(),
                'closing' => $this->signed($close),
                'moved' => $d !== 0 || $c !== 0 || $o !== 0,
            ];
        }

        return [
            'rows' => $rows,
            'total' => [
                'opening' => $this->signed($totals['opening']),
                'debits' => Money::ofMinor($totals['debits'])->format(),
                'credits' => Money::ofMinor($totals['credits'])->absolute()->format(),
                'closing' => $this->signed($totals['closing']),
            ],
        ];
    }

    /** "1,200.00 Dr" or "300.00 Cr", never a bare negative. */
    private function signed(int $minor): string
    {
        $money = Money::ofMinor($minor);

        return $money->absolute()->format().($money->isCredit() && ! $money->isZero() ? ' Cr' : ($money->isZero() ? '' : ' Dr'));
    }

    /**
     * Defaults to the fiscal year to date, the same window the trial balance and
     * the profit and loss default to, so three reports opened side by side
     * describe the same period.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();

        $yearStart = $business === null
            ? $today->copy()->startOfYear()
            : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $yearStart;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
