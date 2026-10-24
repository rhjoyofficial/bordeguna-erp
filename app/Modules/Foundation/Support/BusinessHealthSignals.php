<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\ApprovalInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Business health, rebuilt to "only the necessary part" (Joy, 24 Oct 2026):
 * today's action signals and nothing else. Seven questions an owner asks each
 * morning, each answered with a count, an amount where money is involved, and
 * a link to the list where the work is done.
 *
 * Shared by the /health page and the Owner dashboard's health widget, so the
 * two can never disagree.
 *
 * ⚠⚠ A FIXED NUMBER OF QUERIES. Every signal is one or two aggregate queries,
 * whatever the data holds: no loop asks the database per row. The query-count
 * test (BusinessHealthTest) proves the count does not grow with the data.
 *
 * ⚠ A viewer sees a signal only when they may open the list it links to (the
 * permission named beside it is that list's own route gate). A source that
 * throws drops its signal, never the page.
 *
 * Gone on 24 Oct 2026: late tickets (Tickets are hidden), the cash forecast
 * week (Cash forecast is hidden), late follow-ups, probations and attendance
 * review (they live on My work and Needs attention).
 */
final class BusinessHealthSignals
{
    /** Supplier bills due within this many days (and any already past due). */
    public const BILLS_DUE_DAYS = 7;

    /** Batches whose expiry date falls within this many days (or has passed). */
    public const EXPIRY_DAYS = 30;

    /**
     * @return list<array{key: string, label: string, count: int, amount: ?string, url: string, hint: string, params: array<string, int|string>}>
     */
    public static function for(User $user): array
    {
        $businessId = app(BusinessContext::class)->id();
        $today = Carbon::today();
        $signals = [];

        $add = function (bool $allowed, callable $signal) use (&$signals) {
            if (! $allowed) {
                return;
            }
            try {
                $result = $signal();
                if ($result !== null) {
                    $signals[] = $result;
                }
            } catch (Throwable) {
                // The page never falls over one sick source.
            }
        };

        // 1. Customers past their due date. Gate: the receivables ageing route.
        $add($user->can('accounting.operations.view'), function () use ($today) {
            $overdue = SalesInvoice::query()
                ->select('sales_invoices.customer_id')
                ->withOutstandingPaisa()
                ->whereRaw('COALESCE(sales_invoices.due_date, DATE(sales_invoices.invoice_date)) < ?', [$today->toDateString()]);

            $row = DB::query()->fromSub($overdue, 'overdue')
                ->where('outstanding_paisa', '>', 0)
                ->selectRaw('COUNT(DISTINCT customer_id) AS customers, COUNT(*) AS invoices, COALESCE(SUM(outstanding_paisa), 0) AS paisa')
                ->first();

            return self::signal('customers_overdue', 'Customers overdue', (int) $row->customers, (int) $row->paisa,
                '/accounting/receivables-ageing', '{invoices} unpaid invoices are past their due date.', ['invoices' => (int) $row->invoices]);
        });

        // 2. Supplier bills to pay within the week, and any already late.
        // Gate and rule: the Bills due screen (due date, or the bill date when none).
        $add($user->can('purchasing.reports.view'), function () use ($today) {
            $bills = SupplierBill::query()
                ->select('supplier_bills.id')
                ->owingWithOutstanding()
                ->selectRaw('COALESCE(supplier_bills.due_date, DATE(supplier_bills.bill_date)) AS due_on')
                ->whereRaw('COALESCE(supplier_bills.due_date, DATE(supplier_bills.bill_date)) <= ?', [$today->copy()->addDays(self::BILLS_DUE_DAYS)->toDateString()]);

            $row = DB::query()->fromSub($bills, 'due')
                ->selectRaw('COUNT(*) AS bills, COALESCE(SUM(outstanding_paisa), 0) AS paisa, COALESCE(SUM(CASE WHEN due_on < ? THEN 1 ELSE 0 END), 0) AS late', [$today->toDateString()])
                ->first();

            return self::signal('bills_due', 'Supplier bills due within 7 days', (int) $row->bills, (int) $row->paisa,
                '/purchasing/bills-due?days='.self::BILLS_DUE_DAYS, '{late} of them are already past due.', ['late' => (int) $row->late]);
        });

        // 3. Products at or under their low-stock line, or out of stock: the
        // Low stocks screen's two lists, by its rule.
        $add($user->can('inventory.reports.view'), function () use ($businessId) {
            $totals = DB::table('stock_batches')
                ->where('business_id', $businessId)
                ->where('status', StockBatchStatus::Active->value)
                ->selectRaw('product_id, SUM(current_quantity) AS quantity')
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id');

            $low = 0;
            $out = 0;
            foreach (Product::stocked()->where('is_active', true)->get(['id', 'low_stock_threshold']) as $product) {
                $total = (float) ($totals[$product->id] ?? 0);
                if ($total <= 0) {
                    $out++;
                } elseif (Product::stockLevelIsLow($product->low_stock_threshold, $total)) {
                    $low++;
                }
            }

            return self::signal('low_stock', 'Low stock items', $low + $out, null,
                '/inventory/low-stocks', '{out} of them are out of stock.', ['out' => $out]);
        });

        // 4. Batches expiring within 30 days, and those already past their
        // date still on the shelf. Amount: their value at cost.
        $add($user->can('inventory.reports.view'), function () use ($businessId, $today) {
            $row = DB::table('stock_batches')
                ->where('business_id', $businessId)
                ->where('status', StockBatchStatus::Active->value)
                ->where('current_quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(self::EXPIRY_DAYS)->toDateString())
                ->selectRaw('COUNT(*) AS batches, COALESCE(SUM(current_quantity * unit_cost), 0) AS value, COALESCE(SUM(CASE WHEN expiry_date < ? THEN 1 ELSE 0 END), 0) AS expired', [$today->toDateString()])
                ->first();

            return self::signal('batches_expiring', 'Batches expiring within 30 days', (int) $row->batches,
                (int) round(((float) $row->value) * 100), '/inventory/expiry?within='.self::EXPIRY_DAYS,
                '{expired} of them are already past their date.', ['expired' => (int) $row->expired]);
        });

        // 5. Money accounts at or below their level. ⚠ No minimum level is
        // stored for a money account yet, so the line is zero: cash, bank and
        // mobile accounts holding nothing or less. Courier and gateway accounts
        // are money owed to us, not money to spend, and are left out.
        $add($user->can('accounting.view'), function () use ($businessId) {
            $accounts = MoneyAccount::query()->handled()->where('is_active', true)->get(['id', 'account_id']);
            $heads = $accounts->pluck('account_id')->filter()->unique()->values();

            $balances = $heads->isEmpty() ? collect() : DB::table('journal_lines')
                ->where('business_id', $businessId)
                ->whereIn('account_id', $heads->all())
                ->selectRaw('account_id, SUM(amount_minor) AS balance')
                ->groupBy('account_id')
                ->pluck('balance', 'account_id');

            $low = $accounts->map(fn (MoneyAccount $account) => $account->account_id === null ? 0 : (int) ($balances[$account->account_id] ?? 0))
                ->filter(fn (int $balance) => $balance <= 0);

            return self::signal('money_low', 'Money accounts at zero or below', $low->count(), (int) $low->sum(),
                '/accounting/cash-and-bank', 'No minimum level is set, so zero is the line.', []);
        });

        // 6. Approvals waiting for this person. Shown exactly when the sidebar
        // shows Approvals (HandleInertiaRequests::canReviewApprovals): a
        // standing approver always, anybody else while something waits on
        // them, so the link never meets the inbox's 403.
        $add(true, function () use ($user) {
            $waiting = app(ApprovalInbox::class)->countFor($user);

            if ($waiting === 0 && ! $user->can('inventory.stock.approve') && ! $user->can('purchasing.po.approve')) {
                return null;
            }

            return self::signal('approvals_waiting', 'Approvals waiting for you', $waiting, null,
                '/approvals', 'Somebody cannot move until you decide.', []);
        });

        // 7. Stock reserved for an approved request and not handed over yet
        // (stock_reservations, 17 Oct 2026: staff purchases and owner
        // withdrawals). Only the kinds whose list this person may open count.
        // ⚠ SEAM: when sales orders reserve stock, add their morph class and
        // list here; the query already groups by kind.
        $kinds = array_filter([
            (new StaffPurchaseRequest)->getMorphClass() => $user->can('hrm.employees.manage') ? '/hrm/staff-purchases' : null,
            (new OwnerWithdrawal)->getMorphClass() => $user->can('accounting.owner_withdrawal.view') ? '/accounting/owner-withdrawals' : null,
        ]);
        $add($kinds !== [], function () use ($businessId, $kinds) {
            $rows = DB::table('stock_reservations')
                ->join('stock_batches', 'stock_batches.id', '=', 'stock_reservations.stock_batch_id')
                ->where('stock_reservations.business_id', $businessId)
                ->whereNull('stock_reservations.released_at')
                ->whereIn('stock_reservations.reference_type', array_keys($kinds))
                ->selectRaw('stock_reservations.reference_type AS kind, COUNT(DISTINCT stock_reservations.reference_id) AS documents, COALESCE(SUM(stock_reservations.quantity * stock_batches.unit_cost), 0) AS value')
                ->groupBy('stock_reservations.reference_type')
                ->get()
                ->keyBy('kind');

            $busiest = $rows->sortByDesc('documents')->keys()->first();

            return self::signal('stock_reserved', 'Stock reserved, not handed over', (int) $rows->sum('documents'),
                (int) round(((float) $rows->sum('value')) * 100), $kinds[$busiest] ?? reset($kinds),
                'Approved requests whose goods are held and not yet given out.', []);
        });

        return $signals;
    }

    /**
     * @param  array<string, int|string>  $params
     * @return array{key: string, label: string, count: int, amount: ?string, url: string, hint: string, params: array<string, int|string>}
     */
    private static function signal(string $key, string $label, int $count, ?int $paisa, string $url, string $hint, array $params): array
    {
        $english = $hint;
        foreach ($params as $name => $value) {
            $english = str_replace('{'.$name.'}', (string) $value, $english);
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'amount' => $paisa === null ? null : Money::ofMinor($paisa)->toTaka(),
            'url' => $url,
            'hint' => $english,
            'params' => $params,
        ];
    }
}
