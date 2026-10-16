<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\VatReturn;
use App\Modules\Accounting\Reporting\Definitions\CashPositionReport;
use App\Modules\Accounting\Support\AccountsOverview;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\VatRegime;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\Sources\LoanDuesSource;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\Widgets\BankUnmatchedWidget;
use App\Support\Dashboards\Widgets\CashPositionWidget;
use App\Support\Dashboards\Widgets\MoneyInTransitWidget;
use App\Support\Dashboards\Widgets\PeriodLockWidget;
use App\Support\Dashboards\Widgets\ReceiptsPaymentsWidget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon as LaravelCarbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts > Overview (redesign round 3, 16 Oct 2026, decision D10): where the
 * money is, what needs somebody, the profit for a range, the vouchers, who
 * owes whom, money in and out by day, and the state of the books. It replaces
 * the finance dashboard at the same address and keeps its component name.
 *
 * ⚠⚠ IT COMPUTES NOTHING OF ITS OWN. Every figure comes from the class that
 * already produces it for the statement, report or role-dashboard card of the
 * same name: IncomeStatement, Ageing, CashPositionReport, MoneyAvailability,
 * the Money widgets, SubledgerReconciliation, LoanDuesSource. A dashboard with
 * its own arithmetic is a dashboard that eventually disagrees with the
 * statements, and then nobody knows which to believe. The only new reads are
 * the three in AccountsOverview (voucher counts, unbalanced journal drafts,
 * cheques waiting), which no report produced before.
 *
 * ⚠⚠ EVERYTHING HERE MAY BE ZERO, and that is correct rather than broken on a
 * business that has not posted yet. The page says so out loud.
 *
 * ⚠ Gated on `accounting.view`, the same permission as the statements it
 * summarises. A dashboard is not a softer thing than the report behind it.
 * The loan line shows only to `accounting.loans.view`, and each "start
 * something" button only to the person who may do it.
 */
class FinanceDashboardController extends Controller
{
    /** How many months of history the trend panels show. */
    private const MONTHS = 6;

    /** The ranges the profit, voucher and money-by-day panels follow. */
    private const RANGES = ['this_month', 'last_month', 'this_year'];

    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        /** @var User $user */
        $user = $request->user();
        $businessId = (int) app(BusinessContext::class)->id();
        $today = CarbonImmutable::today();
        [$rangeKey, $from, $to, $rangeLabel] = $this->range($request, $today, $businessId);

        $months = [];

        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            // ⚠ startOfMonth FIRST, then subMonths. The other order overflows on
            // days 29-31 (subtracting a month from the 31st lands in the wrong
            // month), which silently duplicated and skipped months in the trend
            // for a few days each month. Same trap the VAT return already avoids.
            $start = $today->startOfMonth()->subMonths($i);
            $end = $start->endOfMonth();

            // ⚠ The SAME class the income statement screen uses. If the two ever
            // disagree it will be because the statement changed, and this will
            // change with it.
            // ⚠ `toMutable()` returns Carbon\Carbon, and these signatures want
            // Illuminate\Support\Carbon. They are not the same class and PHP
            // will not coerce one to the other.
            $statement = IncomeStatement::between(LaravelCarbon::instance($start), LaravelCarbon::instance($end));

            $months[] = [
                'label' => $start->format('M'),
                'a' => $this->taka($statement->totalIncome()),
                'b' => $this->taka($statement->totalCostOfSales()->plus($statement->totalOperatingExpenses())),
                'profit' => $this->taka($statement->netProfit()),
            ];
        }

        $period = IncomeStatement::between(LaravelCarbon::instance($from), LaravelCarbon::instance($to));

        $receivables = Ageing::receivables(LaravelCarbon::instance($today));
        $payables = Ageing::payables(LaravelCarbon::instance($today));

        // The Money widgets of the role dashboards, read through the same
        // context they get there, so this page and the Finance dashboard agree.
        $context = new WidgetContext($user, $businessId, DateRange::make($rangeKey, $from, $to), DashboardKey::Finance);

        $overview = new AccountsOverview($businessId);
        $vouchers = $overview->voucherCounts($from, $to, $today);
        $unbalanced = $overview->unbalancedJournalDrafts();
        $cheques = $overview->chequesWaiting($today);
        $bank = app(BankUnmatchedWidget::class)->data($context);
        $reconciliation = SubledgerReconciliation::reconcileCurrentBusiness();

        $accounts = MoneyAccount::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get();
        $accruals = $this->accrualsToRelease($today);

        return Inertia::render('accounting/finance-dashboard', [
            'today' => $today->toDateString(),
            'range' => ['key' => $rangeKey, 'label' => $rangeLabel, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'months' => $months,
            'profit' => [
                'income' => $this->taka($period->totalIncome()),
                'costOfSales' => $this->taka($period->totalCostOfSales()),
                'grossProfit' => $this->taka($period->grossProfit()),
                'expenses' => $this->taka($period->totalOperatingExpenses()),
                'netProfit' => $this->taka($period->netProfit()),
                'grossMargin' => $period->grossMarginPercent(),
            ],
            // ⚠ Active money accounts with the balance the payment forms read
            // (MoneyAvailability, courier and gateway lines by their own money
            // account). Cash kinds sit under "Cash and bank"; courier and
            // gateway accounts are money owed to us, not cash.
            'moneyAccounts' => $accounts->map(fn (MoneyAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'kind' => $a->type->label(),
                'is_cash' => MoneyLedgers::hasOwnLedger($a->type),
                'balance' => $this->takaMinor(MoneyAvailability::balanceMinor($a)),
                'has_ledger' => $a->account_id !== null,
            ])->values(),
            'cash' => $this->only(app(CashPositionWidget::class)->data($context), ['value', 'parts']),
            'inTransit' => $this->only(app(MoneyInTransitWidget::class)->data($context), ['value', 'parts']),
            'weOwe' => $this->weOwe($today),
            'receivables' => $this->buckets($receivables),
            'payables' => $this->buckets($payables),
            'owed' => [
                'to_us' => $this->taka($receivables->total()),
                'to_us_overdue' => $this->takaMinor($receivables->total()->minor - $receivables->bucket(AgeingBucket::Current)->minor),
                'by_us' => $this->taka($payables->total()),
                'by_us_overdue' => $this->takaMinor($payables->total()->minor - $payables->bucket(AgeingBucket::Current)->minor),
                'by_us_due_7_days' => $this->takaMinor($this->dueWithin($payables, $today, 7)),
            ],
            'vouchers' => [
                'rows' => array_map(fn (array $row) => $row + ['posted_amount' => $this->takaMinor($row['posted_minor'])], $vouchers['rows']),
                'other_income' => $vouchers['other_income'] + ['amount' => $this->takaMinor($vouchers['other_income']['minor'])],
                'today' => $vouchers['today'],
            ],
            'flow' => $this->only(app(ReceiptsPaymentsWidget::class)->data($context), ['points']),
            'attention' => $this->attention($user, $businessId, $today, $vouchers['open'], $unbalanced, $cheques, $bank, $reconciliation, $accruals),
            'books' => [
                'period' => $this->only(app(PeriodLockWidget::class)->data($context), ['value', 'tone', 'parts']),
                'checks' => ['total' => $reconciliation['checks'], 'differ' => count($reconciliation['problems'])],
                'vat' => $this->vat($businessId),
                'accruals' => $accruals,
                'unmapped' => $accounts->whereNull('account_id')->count(),
            ],
            'can' => [
                'prepare_voucher' => $user->can('accounting.voucher.prepare'),
                'record_income' => $user->can('accounting.income.record'),
                'view_loans' => $user->can('accounting.loans.view'),
            ],
            // ⚠⚠ Said out loud on the screen. A dashboard of zeroes on a business
            // that has not started trading looks identical to a dashboard that is
            // broken, and somebody will report it as a bug.
            'hasPostings' => Journal::query()->exists(),
        ]);
    }

    /**
     * This month (to today), last month, or this fiscal year to today. The
     * fiscal year is the business's own (July to June by default).
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    private function range(Request $request, CarbonImmutable $today, int $businessId): array
    {
        $key = in_array($request->query('range'), self::RANGES, true) ? (string) $request->query('range') : 'this_month';

        if ($key === 'last_month') {
            $from = $today->startOfMonth()->subMonthNoOverflow();

            return [$key, $from, $from->endOfMonth(), $from->format('F Y')];
        }

        if ($key === 'this_year') {
            $business = Business::withoutGlobalScopes()->find($businessId);
            $from = $business === null
                ? $today->startOfYear()
                : CarbonImmutable::instance(FiscalYear::containing(LaravelCarbon::instance($today), $business)->startsOn())->startOfDay();

            return [$key, $from, $today->endOfDay(), $from->format('j M Y').' to '.$today->format('j M Y')];
        }

        $from = $today->startOfMonth();

        return [$key, $from, $today->endOfDay(), $from->format('j').' to '.$today->format('j F Y')];
    }

    /**
     * What needs somebody, most serious first. Each line opens the list behind
     * it, filtered where the list has the filter.
     *
     * @param  array{draft: int, pending_approval: int, oldest_draft: ?string, drafts_by_type: array<string, int>, pending_by_type: array<string, int>}  $open
     * @param  list<array{id: int, title: string, date: string, difference_minor: int, created_by: ?string}>  $unbalanced
     * @param  array{count: int, past_date: int, minor: int, oldest: ?string}  $cheques
     * @param  array<string, mixed>  $bank
     * @param  array{problems: list<string>, warnings: list<string>, checks: int}  $reconciliation
     * @return list<array{key: string, tone: string, count: int, title: string, hint: string, url: string}>
     */
    private function attention(User $user, int $businessId, CarbonImmutable $today, array $open, array $unbalanced, array $cheques, array $bank, array $reconciliation, int $accruals): array
    {
        $items = [];

        if ($reconciliation['problems'] !== []) {
            $items[] = [
                'key' => 'reconciliation',
                'tone' => 'danger',
                'count' => count($reconciliation['problems']),
                'title' => 'Reconciliation differs',
                'hint' => str_replace('—', '-', $reconciliation['problems'][0]),
                'url' => '/accounting/reconciliation',
            ];
        }

        if ($open['pending_approval'] > 0) {
            $type = (string) array_key_first($open['pending_by_type']);
            $items[] = [
                'key' => 'approvals',
                'tone' => 'warning',
                'count' => $open['pending_approval'],
                'title' => 'Vouchers waiting for approval',
                'hint' => 'Above the finance-policy limit: a second person approves and posts them',
                'url' => "/accounting/vouchers/{$type}?status=pending_approval",
            ];
        }

        if ($unbalanced !== []) {
            $first = $unbalanced[0];
            $side = $first['difference_minor'] > 0 ? 'debits' : 'credits';
            $items[] = [
                'key' => 'unbalanced',
                'tone' => 'warning',
                'count' => count($unbalanced),
                'title' => count($unbalanced) === 1 ? 'Journal draft does not balance' : 'Journal drafts do not balance',
                'hint' => $first['title'].($first['created_by'] ? " by {$first['created_by']}" : '')
                    .", {$side} ahead by ".Money::ofMinor(abs($first['difference_minor']))->format(),
                'url' => count($unbalanced) === 1 ? "/accounting/vouchers/doc/{$first['id']}" : '/accounting/vouchers/journal?status=draft',
            ];
        }

        if ($open['draft'] > 0) {
            $byType = $open['drafts_by_type'];
            arsort($byType);
            $words = [];

            foreach ($byType as $type => $count) {
                $words[] = $count.' '.$this->typeWord((string) $type, $count);
            }

            $days = $open['oldest_draft'] === null ? null : (int) CarbonImmutable::parse($open['oldest_draft'])->diffInDays($today);
            $items[] = [
                'key' => 'drafts',
                'tone' => 'info',
                'count' => $open['draft'],
                'title' => 'Drafts not posted yet',
                'hint' => ($days !== null && $days > 0 ? "Oldest {$days} ".($days === 1 ? 'day' : 'days').', ' : '').implode(', ', $words),
                'url' => '/accounting/vouchers/'.array_key_first($byType).'?status=draft',
            ];
        }

        if ((int) ($bank['value'] ?? 0) > 0) {
            $oldest = collect($bank['parts'] ?? [])->firstWhere('label', 'Oldest');
            $items[] = [
                'key' => 'bank',
                'tone' => 'warning',
                'count' => (int) $bank['value'],
                'title' => 'Bank lines to match',
                'hint' => $oldest ? 'Oldest line '.CarbonImmutable::parse($oldest['value'])->format('j M') : 'Imported statement lines with no voucher yet',
                'url' => '/accounting/bank-statements',
            ];
        }

        if ($cheques['count'] > 0) {
            $items[] = [
                'key' => 'cheques',
                'tone' => $cheques['past_date'] > 0 ? 'warning' : 'info',
                'count' => $cheques['count'],
                'title' => $cheques['count'] === 1 ? 'Cheque waiting to clear' : 'Cheques waiting to clear',
                'hint' => ($cheques['past_date'] > 0 ? "{$cheques['past_date']} past its date, " : '').Money::ofMinor($cheques['minor'])->format().' in all',
                'url' => '/accounting/cheques?status=pending',
            ];
        }

        if ($accruals > 0) {
            $items[] = [
                'key' => 'accruals',
                'tone' => 'info',
                'count' => $accruals,
                'title' => 'Accruals to release for '.$today->format('F'),
                'hint' => 'Prepaid and accrued amounts not yet released this month',
                'url' => '/accounting/amortizations',
            ];
        }

        // Loans: the Needs attention source itself, only to whoever may read loans.
        if ($user->can('accounting.loans.view')) {
            foreach (app(LoanDuesSource::class)->items($user, $businessId) as $item) {
                /** @var AttentionItem $item */
                $overdue = str_ends_with($item->key, ':overdue');
                $dueSoon = str_ends_with($item->key, ':due_soon');

                if (! $overdue && ! $dueSoon) {
                    continue;
                }

                $items[] = [
                    'key' => $overdue ? 'loans_overdue' : 'loans_due',
                    'tone' => $overdue ? 'danger' : 'info',
                    'count' => (int) ($item->count ?? 1),
                    'title' => $overdue ? 'Loan instalments past due' : 'Loan instalments due in '.LoanDuesSource::DUE_DAYS.' days',
                    'hint' => $item->reason,
                    'url' => $item->url,
                ];
            }
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $a, array $b) => $rank[$a['tone']] <=> $rank[$b['tone']]);

        return $items;
    }

    private function typeWord(string $type, int $count): string
    {
        $word = match (VoucherType::tryFrom($type)) {
            VoucherType::Payment => 'payment',
            VoucherType::Receipt => 'receipt',
            VoucherType::Contra => 'contra',
            VoucherType::Journal => 'journal',
            default => 'voucher',
        };

        return $count === 1 ? $word : $word.'s';
    }

    /**
     * "Not cash: we owe" from the Cash position report itself: what is
     * payable to couriers and what customers paid in advance.
     *
     * @return array{total: float, rows: list<array{label: string, value: float}>}
     */
    private function weOwe(CarbonImmutable $today): array
    {
        $rows = [];

        foreach ((new CashPositionReport)->rows(new ReportParameters(['as_at' => $today->toDateString()])) as $row) {
            if ($row['owed_by_us'] !== null && (float) $row['owed_by_us'] !== 0.0) {
                $rows[] = ['label' => (string) $row['account'], 'value' => round((float) $row['owed_by_us'], 2)];
            }
        }

        return ['total' => round(array_sum(array_column($rows, 'value')), 2), 'rows' => $rows];
    }

    /** The latest VAT return, shown only when the business is registered. */
    private function vat(int $businessId): ?array
    {
        $business = Business::withoutGlobalScopes()->find($businessId);
        $regime = VatRegime::tryFrom((string) $business?->vat_regime) ?? VatRegime::NotRegistered;

        if ($regime === VatRegime::NotRegistered) {
            return null;
        }

        $latest = VatReturn::query()->orderByDesc('period_start')->first();

        return [
            'regime' => $regime->label(),
            'latest' => $latest === null ? null : ['period' => $latest->period_start->format('F Y'), 'status' => (string) $latest->status],
        ];
    }

    /** Active schedules whose amount for this month has not been released yet. */
    private function accrualsToRelease(CarbonImmutable $today): int
    {
        $month = $today->startOfMonth()->toDateString();

        return AmortizationSchedule::query()
            ->where('status', 'active')
            ->whereDate('start_month', '<=', $month)
            ->where(fn ($q) => $q->whereNull('released_through')->orWhereDate('released_through', '<', $month))
            ->count();
    }

    /** Bills falling due from today to `days` ahead, read off the ageing documents. */
    private function dueWithin(Ageing $ageing, CarbonImmutable $today, int $days): int
    {
        $start = LaravelCarbon::instance($today);
        $end = $start->copy()->addDays($days);
        $minor = 0;

        foreach ($ageing->rows as $row) {
            foreach ($row->documents as $document) {
                if ($document->dueDate !== null && $document->dueDate->betweenIncluded($start, $end)) {
                    $minor += $document->outstanding->minor;
                }
            }
        }

        return $minor;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $payload, array $keys): array
    {
        return array_intersect_key($payload, array_flip($keys));
    }

    /**
     * @return list<array{label:string, value:float}>
     */
    private function buckets(Ageing $ageing): array
    {
        return array_map(
            fn (AgeingBucket $bucket) => [
                'key' => $bucket->value,
                'label' => $bucket->label(),
                'value' => $this->taka($ageing->bucket($bucket)),
            ],
            AgeingBucket::cases(),
        );
    }

    /**
     * ⚠⚠ Money is whole paisa in a signed integer (`amount_minor`); it is NEVER a
     * float in the ledger. This converts at the very edge, for a chart, and the
     * conversion lives in one place so no screen invents its own division.
     */
    private function taka(Money $money): float
    {
        return $this->takaMinor($money->minor);
    }

    private function takaMinor(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
