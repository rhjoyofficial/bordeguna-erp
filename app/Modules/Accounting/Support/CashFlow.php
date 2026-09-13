<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the money actually went.
 *
 * ⚠⚠ THE DIRECT METHOD, COMPUTED FROM THE LEDGER, NOT ESTIMATED FROM PROFIT.
 * The usual indirect presentation starts at net profit and works backwards
 * through accruals. This starts from the cash itself: every journal that touches
 * a cash account IS a cash movement, and the accounts on the other side of that
 * journal say what the movement was for. Nothing is apportioned and nothing is
 * assumed.
 *
 * ⚠⚠ THE ARITHMETIC PROVES ITSELF, AND THAT PROOF IS NOT DECORATION. A journal
 * balances, so within any journal touching cash the non-cash lines sum to
 * exactly the negative of the cash lines. Classify every non-cash line and the
 * three sections must therefore add up to the real change in the cash accounts,
 * which is read separately from the ledger. If they disagree, the statement says
 * so rather than hiding it - unlike a trial balance, which can balance while
 * being wrong.
 *
 * ⚠ A MONEY TRANSFER CORRECTLY SHOWS ALMOST NOTHING. Bank to till has cash on
 * both sides and no non-cash line except the fee, so it nets to zero here and
 * only the fee appears. Moving your own money is not a cash flow.
 */
class CashFlow
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Money $opening,
        public readonly Money $closing,
        /** @var Collection<int, CashFlowLine> */
        public readonly Collection $operating,
        /** @var Collection<int, CashFlowLine> */
        public readonly Collection $investing,
        /** @var Collection<int, CashFlowLine> */
        public readonly Collection $financing,
        public readonly bool $anyCashAccountMapped,
    ) {}

    public static function between(Carbon $from, Carbon $to): self
    {
        // ⚠ Not a courier's account: cash on delivery a courier holds is a
        // receivable (1145), and it becomes cash when the payout lands.
        $cashIds = MoneyAccount::query()->handled()
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // ⚠ Without a single mapped money account there is no "cash" to follow,
        // and every figure below would be a confident zero. The screen says so
        // instead of drawing an empty statement.
        if ($cashIds->isEmpty()) {
            return new self(
                $from, $to, Money::ofMinor(0), Money::ofMinor(0),
                collect(), collect(), collect(), false,
            );
        }

        $opening = Money::ofMinor(self::cashBalance($cashIds, null, $from->copy()->subDay()));
        $closing = Money::ofMinor(self::cashBalance($cashIds, null, $to));

        // Every journal in the window with at least one line on a cash account.
        $journalIds = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereIn('journal_lines.account_id', $cashIds)
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->distinct()
            ->pluck('journals.id');

        if ($journalIds->isEmpty()) {
            return new self($from, $to, $opening, $closing, collect(), collect(), collect(), true);
        }

        // ⚠ The counterpart lines, and ONLY those. The cash lines themselves are
        // the movement being explained, not part of the explanation.
        $totals = DB::table('journal_lines')
            ->whereIn('journal_id', $journalIds)
            ->whereNotIn('account_id', $cashIds)
            ->groupBy('account_id')
            ->select('account_id', DB::raw('SUM(amount_minor) as total'))
            ->pluck('total', 'account_id');

        $accounts = Account::query()->whereIn('id', $totals->keys())->inChartOrder()->get();

        $sections = ['operating' => collect(), 'investing' => collect(), 'financing' => collect()];

        foreach ($accounts as $account) {
            // ⚠⚠ NEGATED. A journal sums to zero, so cash received equals minus
            // the counterpart lines: an expense DEBIT of 500 means 500 left.
            $flow = Money::ofMinor(-(int) $totals[$account->id]);

            if ($flow->minor === 0) {
                continue;
            }

            $sections[self::classify($account)]->push(new CashFlowLine($account, $flow));
        }

        return new self(
            $from, $to, $opening, $closing,
            $sections['operating'], $sections['investing'], $sections['financing'], true,
        );
    }

    /** The chart groups these sections hang from. */
    private const NON_CURRENT_ASSETS = '1200';

    private const NON_CURRENT_LIABILITIES = '2200';

    /**
     * ⚠ Classified by WHERE THE ACCOUNT SITS IN THE CHART, by walking its
     * parents - not by a list of individual account codes. Add a new fixed-asset
     * account under Non-Current Assets and it lands in investing without anyone
     * editing this file.
     *
     * ⚠ The walk is on CODE rather than `system_slug`, because the seeded group
     * rows (1200, 2200) carry no slug - only the postable accounts beneath them
     * do. Matching on a slug that is always null would send every account to
     * operating while looking entirely deliberate.
     */
    private static function classify(Account $account): string
    {
        // Owner capital, drawings, retained earnings - all financing.
        if ($account->type === AccountType::Equity) {
            return 'financing';
        }

        for ($node = $account; $node !== null; $node = $node->parent) {
            if ($node->code === self::NON_CURRENT_ASSETS) {
                return 'investing';
            }

            if ($node->code === self::NON_CURRENT_LIABILITIES) {
                return 'financing';
            }
        }

        return 'operating';
    }

    private static function cashBalance(Collection $cashIds, ?Carbon $from, Carbon $to): int
    {
        return (int) DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereIn('journal_lines.account_id', $cashIds)
            ->when($from, fn ($q) => $q->whereDate('journals.journal_date', '>=', $from->toDateString()))
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->sum('journal_lines.amount_minor');
    }

    public function totalOperating(): Money
    {
        return self::total($this->operating);
    }

    public function totalInvesting(): Money
    {
        return self::total($this->investing);
    }

    public function totalFinancing(): Money
    {
        return self::total($this->financing);
    }

    public function netMovement(): Money
    {
        return Money::ofMinor(
            $this->totalOperating()->minor + $this->totalInvesting()->minor + $this->totalFinancing()->minor,
        );
    }

    /**
     * ⚠⚠ THE CHECK THAT MEANS SOMETHING. The classified movements are compared
     * with the cash balance actually read from the ledger. Two independent
     * routes to the same number, so agreement is evidence rather than tautology.
     */
    public function difference(): Money
    {
        return Money::ofMinor($this->opening->minor + $this->netMovement()->minor - $this->closing->minor);
    }

    public function proves(): bool
    {
        return $this->difference()->minor === 0;
    }

    /** @param  Collection<int, CashFlowLine>  $lines */
    private static function total(Collection $lines): Money
    {
        return Money::ofMinor((int) $lines->sum(fn (CashFlowLine $line) => $line->flow->minor));
    }
}
