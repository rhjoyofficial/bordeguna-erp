<?php

namespace App\Modules\Accounting\Reporting\Definitions;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingRow;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\Contracts\ChartsOnScreen;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Cash position as at a date (reports pack, 11 Oct 2026).
 *
 * ⚠⚠ The audit's bug 2, answered as a report: "cash" on the cockpit, the
 * forecast and the morning briefing summed every journal line that named a
 * money account, which counts cash on delivery the courier still holds (1145)
 * and online payments the gateway has not paid out (1146) as money in the bank.
 * Here the three are kept apart:
 *
 *   Cash and bank        each cash, bank and mobile-wallet ledger head (MoneyLedgers)
 *   Not cash, owed to us money with each courier (1145) and gateway (1146), BY
 *                        journal_lines.money_account_id, so two couriers never
 *                        each show the combined figure
 *   Not cash, owed by us payable to each courier (2165), customer advances (2150),
 *                        and receipts not yet applied to an invoice (credit
 *                        sitting in 1140: order advances and money on account),
 *                        and, customer by customer, any further credit a
 *                        customer holds in 1140 (a return not refunded or
 *                        applied: F33, 17 Oct 2026)
 *
 * ⚠ Three money COLUMNS rather than one, so no total anywhere adds a liability
 * to an asset. The cash column's total ties to MoneyLedgers::cashLines as at
 * the day.
 *
 * ⚠ Posted journal lines dated on or before the day; raw tables, so the
 * business is filtered by hand.
 */
class CashPositionReport implements ChartsOnScreen, ReportDefinition
{
    private const CASH = 'Cash and bank';

    private const COURIERS = 'With couriers (not cash)';

    private const GATEWAYS = 'With payment gateways (not cash)';

    private const PAYABLE = 'Payable to couriers (not cash)';

    private const ADVANCES = 'Customer advances (not cash)';

    public function key(): string
    {
        return 'cash_position';
    }

    public function name(): string
    {
        return 'Cash position';
    }

    public function description(): string
    {
        return 'As at a date: each cash, bank and mobile wallet balance from the ledger, then, kept apart and labelled not cash, money with each courier and gateway, what we owe couriers, and customer advances.';
    }

    public function group(): string
    {
        return 'Accounting';
    }

    public function permission(): string
    {
        return 'accounting.view';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::date('as_at', 'As at', required: true, default: now()->toDateString()),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('section', 'Section', 24),
            ReportColumn::text('account', 'Account', 30),
            ReportColumn::money('cash', 'Cash and bank', 16),
            ReportColumn::money('owed_to_us', 'Not cash: owed to us', 16),
            ReportColumn::money('owed_by_us', 'Not cash: owed by us', 16),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['cash', 'owed_to_us', 'owed_by_us'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $business = (int) app(BusinessContext::class)->id();
            $on = $this->asAt($parameters)->toDateString();

            foreach ($this->cashRows($business, $on) as $row) {
                yield $row;
            }

            foreach ([['courier_receivable', self::COURIERS, 1], ['gateway_receivable', self::GATEWAYS, 1], ['courier_payable', self::PAYABLE, -1]] as [$slug, $section, $sign]) {
                foreach ($this->byMoneyAccount($business, $on, $slug) as $name => $minor) {
                    yield $this->row($section, $name, owedToUs: $sign > 0 ? $minor : 0, owedByUs: $sign < 0 ? -$minor : 0);
                }
            }

            $advances = Account::bySlug('customer_advances');

            if ($advances !== null) {
                $minor = (int) $this->lines($business, $on)->where('journal_lines.account_id', $advances->id)->sum('journal_lines.amount_minor');
                yield $this->row(self::ADVANCES, '2150 Advances from customers', owedByUs: -$minor);
            }

            [$orderAdvances, $onAccount, $receiptsByCustomer] = $this->unappliedReceipts($business, $on);
            yield $this->row(self::ADVANCES, 'Paid for orders not yet invoiced (held in 1140)', owedByUs: $orderAdvances);
            yield $this->row(self::ADVANCES, 'Other receipts not applied to an invoice (held in 1140)', owedByUs: $onAccount);

            $credits = $this->returnCredits($this->asAt($parameters), $receiptsByCustomer);

            foreach ($credits as $name => $minor) {
                yield $this->row(self::ADVANCES, "Credit owed to {$name}, from returns (held in 1140)", owedByUs: $minor);
            }

            if ($credits === []) {
                yield $this->row(self::ADVANCES, 'Credit owed to customers from returns (held in 1140)', owedByUs: 0);
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return (int) DB::table('money_accounts')->where('business_id', (int) app(BusinessContext::class)->id())->count() + 3;
    }

    public function countRows(ReportParameters $parameters): int
    {
        return $this->rows($parameters)->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Cash position',
            subtitle: 'As at '.$this->asAt($parameters)->format('d M Y'),
            note: 'Basis: posted journal lines dated on or before the day. Cash and bank is each cash, bank and mobile wallet ledger head and is the only cash on this page. '
                .'Money with a courier (1145) or a payment gateway (1146) is owed to us, not cash, until it is paid out to a bank or wallet; it is shown per courier and gateway by the account each journal line names. '
                .'Payable to couriers is 2165. Customer advances are 2150 plus receipts not yet applied to any invoice dated by the day, which sit as credit in Accounts Receivable (1140), '
                .'plus, customer by customer, any further credit a customer holds in 1140 after what they owe, such as a return not yet refunded or applied. '
                .'None of the not cash amounts is added to cash.',
        );
    }

    public function chart(ReportParameters $parameters): ?array
    {
        return [
            'type' => 'stacked_bar',
            'title' => 'Cash against money that is not cash',
            'label' => 'section',
            'series' => [
                ['key' => 'cash', 'label' => 'Cash and bank'],
                ['key' => 'owed_to_us', 'label' => 'Owed to us'],
                ['key' => 'owed_by_us', 'label' => 'Owed by us'],
            ],
        ];
    }

    private function asAt(ReportParameters $parameters): Carbon
    {
        return $parameters->date('as_at') ?? Carbon::today();
    }

    private function lines(int $business, string $on): Builder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.business_id', $business)
            ->where('journals.business_id', $business)
            ->whereDate('journals.journal_date', '<=', $on);
    }

    /**
     * One row per cash, bank and wallet ledger head: every money account of
     * those types (a zero balance is still an answer), plus any head with
     * lines and no account behind it, so the column ties to the group total.
     *
     * @return list<array<string, mixed>>
     */
    private function cashRows(int $business, string $on): array
    {
        $balances = $this->lines($business, $on)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.parent_id', MoneyLedgers::groupIds())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as account_id, SUM(journal_lines.amount_minor) as minor')
            ->pluck('minor', 'account_id')
            ->mapWithKeys(fn ($minor, $id) => [(int) $id => (int) $minor])
            ->all();

        $accounts = DB::table('money_accounts')
            ->where('business_id', $business)
            ->whereIn('type', array_keys(MoneyLedgers::KINDS))
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'account_id']);

        $rows = [];
        $seen = [];

        foreach ($accounts as $account) {
            $head = $account->account_id === null ? null : (int) $account->account_id;
            $minor = $head !== null && ! isset($seen[$head]) ? ($balances[$head] ?? 0) : 0;

            if ($head !== null) {
                $seen[$head] = true;
            }

            $type = MoneyAccountType::tryFrom((string) $account->type)?->label() ?? (string) $account->type;
            $rows[] = $this->row(self::CASH, "{$account->name} ({$type})", cash: $minor);
        }

        foreach ($balances as $head => $minor) {
            if (! isset($seen[$head]) && $minor !== 0) {
                $name = (string) DB::table('accounts')->where('id', $head)->value('name');
                $rows[] = $this->row(self::CASH, $name.' (no money account)', cash: $minor);
            }
        }

        return $rows;
    }

    /**
     * A shared head split by the money account each line names. Lines naming
     * none are kept, under their own label, so the head still adds up.
     *
     * @return array<string, int> label => signed paisa (debit positive)
     */
    private function byMoneyAccount(int $business, string $on, string $slug): array
    {
        $account = Account::bySlug($slug);

        if ($account === null) {
            return [];
        }

        $sums = $this->lines($business, $on)
            ->where('journal_lines.account_id', $account->id)
            ->groupBy('journal_lines.money_account_id')
            ->selectRaw('journal_lines.money_account_id as money_account_id, SUM(journal_lines.amount_minor) as minor')
            ->get();

        $names = DB::table('money_accounts')->where('business_id', $business)
            ->whereIn('id', $sums->pluck('money_account_id')->filter()->all())
            ->pluck('name', 'id');

        $out = [];

        foreach ($sums as $sum) {
            if ((int) $sum->minor === 0) {
                continue;
            }

            $label = $sum->money_account_id === null
                ? 'Not named on the journal line'
                : (string) ($names[$sum->money_account_id] ?? 'Account '.$sum->money_account_id);
            $out[$label] = ($out[$label] ?? 0) + (int) $sum->minor;
        }

        ksort($out);

        return $out;
    }

    /**
     * What each customer in credit is owed beyond the receipts already listed:
     * mostly a sales return not yet refunded or applied (F33, 17 Oct 2026: the
     * dealer's 807.50 return credit showed nowhere here).
     *
     * ⚠ PER CUSTOMER NET. The customer's position is the receivables ageing's
     * own row total (it ties to 1140), so a return credit against a customer
     * who still owes on an open invoice is not counted: they owe us less, we do
     * not owe them. The unapplied receipts listed above are taken off first, so
     * no taka is counted twice.
     *
     * @param  array<int, int>  $receiptsByCustomer  paisa of unapplied receipts already listed, by customer id
     * @return array<string, int> customer name => paisa we owe them
     */
    private function returnCredits(Carbon $asAt, array $receiptsByCustomer): array
    {
        $out = [];

        foreach (Ageing::receivables($asAt->copy()->startOfDay())->rows as $row) {
            /** @var AgeingRow $row */
            $credit = -$row->total()->minor - ($receiptsByCustomer[$row->partyId] ?? 0);

            if ($row->total()->isCredit() && $credit > 0) {
                $out[$row->partyName] = ($out[$row->partyName] ?? 0) + $credit;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Receipts to the day less what invoices dated by the day have taken: the
     * credit those receipts still leave in 1140. Order advances apart from the
     * rest. Same as-at rule as the receivables ageing.
     *
     * @return array{0: int, 1: int, 2: array<int, int>} paisa: order advances, other receipts on account, both by customer id
     */
    private function unappliedReceipts(int $business, string $on): array
    {
        $allocated = DB::table('customer_receipt_allocations')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'customer_receipt_allocations.sales_invoice_id')
            ->where('customer_receipt_allocations.business_id', $business)
            ->whereDate('sales_invoices.invoice_date', '<=', $on)
            ->groupBy('customer_receipt_allocations.customer_receipt_id')
            ->selectRaw('customer_receipt_allocations.customer_receipt_id as receipt_id, SUM(customer_receipt_allocations.amount) as amount');

        $rows = DB::table('customer_receipts')
            ->leftJoinSub($allocated, 'a', 'a.receipt_id', '=', 'customer_receipts.id')
            ->where('customer_receipts.business_id', $business)
            ->whereDate('customer_receipts.received_on', '<=', $on)
            // ⭐ Corrections wave 1: a reversed receipt counts nowhere.
            ->whereNull('customer_receipts.reversed_at')
            ->selectRaw('customer_receipts.customer_id as customer_id')
            ->selectRaw('CASE WHEN customer_receipts.sales_order_id IS NULL THEN 0 ELSE 1 END as for_order')
            ->selectRaw('SUM(customer_receipts.amount - COALESCE(a.amount, 0)) as unapplied')
            ->groupBy('customer_receipts.customer_id')
            ->groupByRaw('CASE WHEN customer_receipts.sales_order_id IS NULL THEN 0 ELSE 1 END')
            ->get();

        $orders = 0;
        $other = 0;
        $byCustomer = [];

        foreach ($rows as $row) {
            $minor = (int) round((float) $row->unapplied * 100);
            $byCustomer[(int) $row->customer_id] = ($byCustomer[(int) $row->customer_id] ?? 0) + $minor;

            if ((int) $row->for_order === 1) {
                $orders += $minor;
            } else {
                $other += $minor;
            }
        }

        return [$orders, $other, $byCustomer];
    }

    /** @return array<string, mixed> */
    private function row(string $section, string $account, int $cash = 0, int $owedToUs = 0, int $owedByUs = 0): array
    {
        return [
            'section' => $section,
            'account' => $account,
            'cash' => $section === self::CASH ? $cash / 100 : null,
            'owed_to_us' => in_array($section, [self::COURIERS, self::GATEWAYS], true) ? $owedToUs / 100 : null,
            'owed_by_us' => in_array($section, [self::PAYABLE, self::ADVANCES], true) ? $owedByUs / 100 : null,
        ];
    }
}
