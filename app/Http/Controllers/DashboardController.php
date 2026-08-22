<?php

namespace App\Http\Controllers;

use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The chart ranges the range pills offer. Value is [interval, count]:
     * daily buckets for the short windows, monthly for the long ones.
     */
    private const RANGES = [
        '1w' => ['day', 7],
        '1m' => ['day', 30],
        '3m' => ['month', 3],
        '6m' => ['month', 6],
        '1y' => ['month', 12],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $canViewInventory = $user->can('inventory.reports.view');
        $canViewPurchasing = $user->can('purchasing.reports.view');
        $canViewSales = $user->can('sales.reports.view');
        $canViewAccounting = $user->can('accounting.view');
        $canSeeApprovals = $user->can('inventory.stock.approve') || $user->can('purchasing.po.approve');

        $range = array_key_exists($request->query('range', ''), self::RANGES) ? $request->query('range') : '6m';

        return Inertia::render('dashboard', [
            'unpriced_sellable' => $canViewSales || $user->can('sales.prices.manage') ? $this->unpricedSellable() : null,

            'total_sales' => $canViewSales ? $this->sum(SalesInvoice::query(), 'total') : null,
            'total_purchases' => $canViewPurchasing ? $this->sum(SupplierBill::query(), 'total') : null,
            'total_sales_returns' => $canViewSales ? $this->sum(SalesReturn::query(), 'credit_total') : null,
            'total_purchase_returns' => $canViewPurchasing ? $this->sum(SupplierCreditNote::query(), 'total') : null,

            'net_profit_month' => $canViewAccounting ? $this->netProfitThisMonth() : null,
            'invoice_due' => $canViewSales ? $this->invoiceDue() : null,
            'total_expenses' => $canViewPurchasing ? $this->sum(ExpenseVoucher::query(), 'total') : null,
            'customer_refunds' => $canViewSales ? $this->sum(CustomerRefund::query(), 'amount') : null,

            'stock_value' => $canViewInventory ? $this->stockValue() : null,
            'low_stock_count' => $canViewInventory ? $this->lowStockCount() : null,
            'open_po_count' => $canViewPurchasing ? $this->openPurchaseOrderCount() : null,
            'pending_approval_count' => $canSeeApprovals ? $this->pendingApprovalCount() : null,

            'trade_chart' => $canViewSales || $canViewPurchasing
                ? $this->tradeChart($range, $canViewSales, $canViewPurchasing)
                : null,
            'trade_chart_range' => $range,

            'recent_transactions' => $this->recentTransactions($canViewSales, $canViewPurchasing),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * ⚠⚠ ALL MONEY LEAVES THIS CONTROLLER FORMATTED. `SUM()` over decimal
     * columns returns `19200.00000000` on MySQL and `19200` on SQLite, and the
     * raw string has reached a real user on this project twice. Third shape,
     * same fix: format at the edge.
     */
    private function sum($query, string $column): string
    {
        return number_format((float) ($query->sum($column) ?: 0), 2);
    }

    /**
     * The single most urgent fact for this business right now: a sellable
     * product with no price in force refuses its first sale. Same query as
     * cutover:preflight's Selling prices check, so the banner and the
     * preflight can never disagree.
     */
    private function unpricedSellable(): array
    {
        $sellable = Product::where('is_active', true)
            ->where('type', ProductType::FinishedGood)
            ->count();

        if ($sellable === 0) {
            return ['unpriced' => 0, 'sellable' => 0];
        }

        $priced = ProductPrice::query()
            ->inForceOn(now())
            ->distinct()
            ->count('product_id');

        return ['unpriced' => max(0, $sellable - $priced), 'sellable' => $sellable];
    }

    private function netProfitThisMonth(): string
    {
        return IncomeStatement::between(now()->startOfMonth(), now()->endOfMonth())
            ->netProfit()
            ->format();
    }

    /**
     * What customers still owe: invoiced minus receipts minus return credits,
     * the aggregate of SalesInvoice::outstanding() without walking invoices
     * one by one. The allocation subqueries inherit the invoice's business
     * scope, so an unscoped allocation row cannot leak across businesses.
     */
    private function invoiceDue(): string
    {
        $invoiced = (float) (SalesInvoice::query()->sum('total') ?: 0);
        $received = (float) (CustomerReceiptAllocation::query()
            ->whereIn('sales_invoice_id', SalesInvoice::query()->select('id'))
            ->sum('amount') ?: 0);
        $credited = (float) (SalesReturnAllocation::query()
            ->whereIn('sales_invoice_id', SalesInvoice::query()->select('id'))
            ->sum('amount') ?: 0);

        return number_format($invoiced - $received - $credited, 2);
    }

    private function stockValue(): string
    {
        $value = StockBatch::where('status', StockBatchStatus::Active)
            ->selectRaw('SUM(current_quantity * unit_cost) as value')
            ->value('value') ?? 0;

        return number_format((float) $value, 2);
    }

    private function lowStockCount(): int
    {
        return Product::stocked()->where('is_active', true)->get()
            ->filter->isBelowLowStockThreshold()
            ->count();
    }

    private function openPurchaseOrderCount(): int
    {
        return PurchaseOrder::whereIn('status', [
            PurchaseOrderStatus::Open,
            PurchaseOrderStatus::PartiallyReceived,
        ])->count();
    }

    private function pendingApprovalCount(): int
    {
        return ApprovalRequest::where('status', ApprovalStatus::Pending)->count();
    }

    /**
     * Sales and purchases over the selected window, bucketed daily or
     * monthly. Sales = invoiced value by invoice_date; purchases = billed
     * value by bill_date, so both series are accounting-real documents
     * rather than intentions (a PO is a promise, a bill is a cost).
     */
    private function tradeChart(string $range, bool $withSales, bool $withPurchases): array
    {
        [$interval, $count] = self::RANGES[$range];

        $buckets = collect(range($count - 1, 0))->map(fn (int $i) => $interval === 'day'
            ? now()->subDays($i)->startOfDay()
            : now()->subMonths($i)->startOfMonth());

        $bucketKey = fn (Carbon $date) => $interval === 'day' ? $date->format('Y-m-d') : $date->format('Y-m');
        $bucketLabel = fn (Carbon $date) => $interval === 'day' ? $date->format('j M') : $date->format('M');

        $salesByBucket = $withSales
            ? SalesInvoice::query()
                ->where('invoice_date', '>=', $buckets->first())
                ->get(['invoice_date', 'total'])
                ->groupBy(fn (SalesInvoice $invoice) => $bucketKey($invoice->invoice_date))
                ->map(fn ($invoices) => $invoices->sum(fn ($invoice) => (float) $invoice->total))
            : collect();

        $purchasesByBucket = $withPurchases
            ? SupplierBill::query()
                ->where('bill_date', '>=', $buckets->first())
                ->get(['bill_date', 'total'])
                ->groupBy(fn (SupplierBill $bill) => $bucketKey($bill->bill_date))
                ->map(fn ($bills) => $bills->sum(fn ($bill) => (float) $bill->total))
            : collect();

        return $buckets->map(fn (Carbon $bucket) => [
            'label' => $bucketLabel($bucket),
            'sales' => $withSales ? round($salesByBucket->get($bucketKey($bucket), 0), 2) : null,
            'purchases' => $withPurchases ? round($purchasesByBucket->get($bucketKey($bucket), 0), 2) : null,
        ])->values()->all();
    }

    private function recentTransactions(bool $withSales, bool $withPurchases): array
    {
        return [
            'invoices' => $withSales
                ? SalesInvoice::with('customer')->latest('invoice_date')->latest('id')->limit(5)->get()
                    ->map(fn (SalesInvoice $invoice) => [
                        'id' => $invoice->id,
                        'reference' => $invoice->invoice_number,
                        'party' => $invoice->customer?->name,
                        'date' => $invoice->invoice_date->toDateString(),
                        'amount' => number_format((float) $invoice->total, 2),
                        'url' => "/sales/invoices/{$invoice->id}",
                    ])->all()
                : null,
            'sales_orders' => $withSales
                ? SalesOrder::with('customer')->latest('ordered_at')->latest('id')->limit(5)->get()
                    ->map(fn (SalesOrder $order) => [
                        'id' => $order->id,
                        'reference' => $order->order_number,
                        'party' => $order->customer?->name,
                        'date' => $order->ordered_at->toDateString(),
                        'amount' => ucwords(str_replace('_', ' ', $order->status->value)),
                        'url' => "/sales/orders/{$order->id}",
                    ])->all()
                : null,
            'purchase_orders' => $withPurchases
                ? PurchaseOrder::with('supplier')->latest('created_at')->latest('id')->limit(5)->get()
                    ->map(fn (PurchaseOrder $po) => [
                        'id' => $po->id,
                        'reference' => $po->po_number,
                        'party' => $po->supplier?->name,
                        'date' => $po->created_at->toDateString(),
                        'amount' => ucwords(str_replace('_', ' ', $po->status->value)),
                        'url' => "/purchasing/purchase-orders/{$po->id}",
                    ])->all()
                : null,
            'expenses' => $withPurchases
                ? ExpenseVoucher::latest('spent_on')->latest('id')->limit(5)->get()
                    ->map(fn (ExpenseVoucher $voucher) => [
                        'id' => $voucher->id,
                        'reference' => $voucher->voucher_number,
                        'party' => null,
                        'date' => $voucher->spent_on->toDateString(),
                        'amount' => number_format((float) $voucher->total, 2),
                        'url' => '/purchasing/expenses',
                    ])->all()
                : null,
            'returns' => $withSales
                ? SalesReturn::with('customer')->latest('returned_at')->latest('id')->limit(5)->get()
                    ->map(fn (SalesReturn $return) => [
                        'id' => $return->id,
                        'reference' => $return->return_number,
                        'party' => $return->customer?->name,
                        'date' => $return->returned_at->toDateString(),
                        'amount' => number_format((float) $return->credit_total, 2),
                        'url' => "/sales/returns/{$return->id}",
                    ])->all()
                : null,
        ];
    }

    private function recentActivity(): array
    {
        return ActivityLogEntry::with('causer')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (ActivityLogEntry $entry) => [
                'id' => $entry->id,
                'description' => trim($this->subjectLabel($entry->subject_type).' '.$entry->description),
                'causer_name' => $entry->causer?->name,
                'created_at' => $entry->created_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Turns "App\Modules\Purchasing\Models\PurchaseOrder" into "Purchase Order"
     * so the feed reads "Purchase Order updated" instead of a bare "updated".
     */
    private function subjectLabel(?string $subjectType): string
    {
        if ($subjectType === null) {
            return '';
        }

        $shortName = class_basename($subjectType);

        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $shortName));
    }
}
