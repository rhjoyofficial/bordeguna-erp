<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucherLine;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where purchases stand, as numbers (Purchases redesign, 15 Oct 2026).
 *
 * ⚠⚠ ONE SOURCE FOR EVERY FIGURE the Purchases overview, the Bills, Payments
 * and Credit notes screens show in their money strip. Each number is read
 * from the same scope its list reads (`owingWithOutstanding`, `stillBillable`,
 * the approval request's own status), so a card never says a count its list
 * cannot show, and the strip on Bills agrees with the overview.
 *
 * ⛔ Nothing here is derived twice. What the business owes by age is
 * `Ageing::payables()`, read by the overview controller; this class only
 * reads the documents.
 */
class PurchasesPulse
{
    /** The overdue test, the same one the ageing uses: due date, or the bill date without one. */
    private static function overdueOn(Carbon $today): string
    {
        return 'COALESCE(supplier_bills.due_date, supplier_bills.bill_date) < \''.$today->toDateString().'\'';
    }

    /** Bills still owing, as the payment form sees them. */
    private static function owing(): Builder
    {
        return SupplierBill::query()->owingWithOutstanding();
    }

    /**
     * @param  Builder<SupplierBill>  $query
     * @return array{count: int, amount: string}
     */
    private static function countAndSum(Builder $query): array
    {
        $rows = $query->get();

        return [
            'count' => $rows->count(),
            'amount' => Money::ofMinor((int) $rows->sum('outstanding_paisa'))->format(),
        ];
    }

    /**
     * The purchase chain, one number per step, each with the list it opens.
     *
     * @return array<string, mixed>
     */
    public static function chain(Carbon $today): array
    {
        $pendingIds = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereHas('approvalRequest', fn (Builder $r) => $r->where('status', ApprovalStatus::Pending->value))
            ->pluck('id');

        // 17 Oct 2026: the stored paisa totals, summed as integers; no
        // quantity x rate in SQL, which rounds its own way.
        $orderedMinor = (int) PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', $pendingIds)
            ->selectRaw('COALESCE(SUM(line_total_paisa), 0) as v')
            ->value('v');

        $awaitingGoods = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereHas('approvalRequest', fn (Builder $r) => $r->where('status', ApprovalStatus::Approved->value));

        $toBill = self::ordersToBill();
        $toBillIds = $toBill->pluck('id');
        $oldestReceipt = $toBillIds->isEmpty()
            ? null
            : GoodsReceipt::query()->whereIn('purchase_order_id', $toBillIds)->min('received_at');

        $open = self::countAndSum(self::owing());
        $dueWeek = self::countAndSum(self::owing()
            ->whereNotNull('supplier_bills.due_date')
            ->whereDate('supplier_bills.due_date', '>=', $today->toDateString())
            ->whereDate('supplier_bills.due_date', '<=', $today->copy()->addDays(7)->toDateString()));

        return [
            'awaiting_approval' => [
                'count' => $pendingIds->count(),
                'ordered' => Money::ofMinor($orderedMinor)->format(),
                'numbers' => PurchaseOrder::query()->whereIn('id', $pendingIds)->orderBy('id')->limit(3)->pluck('po_number')->all(),
                'url' => '/purchasing/purchase-orders?approval=pending',
            ],
            'awaiting_goods' => [
                'count' => (clone $awaitingGoods)->count(),
                'partly' => (clone $awaitingGoods)->where('status', PurchaseOrderStatus::PartiallyReceived->value)->count(),
                'url' => '/purchasing/purchase-orders?stage=awaiting_goods',
            ],
            'received_not_billed' => [
                'count' => $toBill->count(),
                'oldest_days' => $oldestReceipt === null ? null : (int) Carbon::parse($oldestReceipt)->startOfDay()->diffInDays($today->copy()->startOfDay()),
                'numbers' => $toBill->take(3)->pluck('po_number')->all(),
                'url' => '/purchasing/purchase-orders?stage=to_bill',
            ],
            'bills_open' => [
                'count' => $open['count'],
                'amount' => $open['amount'],
                'due_this_week' => $dueWeek['count'],
                'url' => '/purchasing/bills?status=open',
            ],
            // ⚠ Bug hunt 16 Oct 2026: a REJECTED order keeps the status Open,
            // so it was counted as an open order for ever. Waiting for approval
            // still counts: that order is in flight.
            'open_orders' => PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereDoesntHave('approvalRequest', fn (Builder $r) => $r->where('status', ApprovalStatus::Rejected->value))
                ->count(),
            'suppliers_open' => PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereDoesntHave('approvalRequest', fn (Builder $r) => $r->where('status', ApprovalStatus::Rejected->value))
                ->distinct()->count('supplier_id'),
        ];
    }

    /**
     * Orders with goods received and not yet billed, oldest first: the same
     * test the bill form uses to offer an order (`stillBillable`).
     *
     * @return Collection<int, PurchaseOrder>
     */
    public static function ordersToBill(): Collection
    {
        return PurchaseOrder::query()
            ->with('supplier:id,name')
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereHas('lines', fn (Builder $line) => $line->stillBillable())
            ->orderBy('id')
            ->get(['id', 'po_number', 'supplier_id']);
    }

    /**
     * The money strip over Bills, Payments and Credit notes: open, overdue,
     * due in seven days, paid this month, received and not yet billed.
     *
     * @return array<string, mixed>
     */
    public static function moneyStrip(Carbon $today): array
    {
        $open = self::countAndSum(self::owing());

        $overdueRows = self::owing()->whereRaw(self::overdueOn($today))->get();
        $oldestDue = $overdueRows->map(fn (SupplierBill $bill) => $bill->due_date ?? $bill->bill_date)->min();

        $dueWeek = self::countAndSum(self::owing()
            ->whereNotNull('supplier_bills.due_date')
            ->whereDate('supplier_bills.due_date', '>=', $today->toDateString())
            ->whereDate('supplier_bills.due_date', '<=', $today->copy()->addDays(7)->toDateString()));

        $paid = SupplierPayment::query()->live()
            ->whereDate('paid_on', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereDate('paid_on', '<=', $today->toDateString())
            ->with('moneyAccount:id,name')
            ->get(['id', 'amount', 'money_account_id']);

        $toBill = self::ordersToBill();

        return [
            'open' => [
                'count' => $open['count'],
                'amount' => $open['amount'],
                'url' => '/purchasing/bills?status=open',
            ],
            'overdue' => [
                'count' => $overdueRows->count(),
                'amount' => Money::ofMinor((int) $overdueRows->sum('outstanding_paisa'))->format(),
                'oldest_days' => $oldestDue === null ? null : (int) Carbon::parse($oldestDue)->startOfDay()->diffInDays($today->copy()->startOfDay()),
                'url' => '/purchasing/bills?status=overdue',
            ],
            'due_week' => [
                'count' => $dueWeek['count'],
                'amount' => $dueWeek['amount'],
                'url' => '/purchasing/bills?status=due_week',
            ],
            'paid_month' => [
                'count' => $paid->count(),
                'amount' => Money::sum($paid->map(fn (SupplierPayment $p) => Money::ofTaka((string) $p->amount)))->format(),
                'label' => 'Paid in '.$today->format('F'),
                'by_account' => $paid->groupBy(fn (SupplierPayment $p) => $p->moneyAccount?->name ?? 'Unnamed account')
                    ->map(fn (Collection $group, string $name) => ['name' => $name, 'count' => $group->count()])
                    ->sortByDesc('count')->values()->all(),
                'url' => '/purchasing/payments?from='.$today->copy()->startOfMonth()->toDateString(),
            ],
            'to_bill' => [
                'count' => $toBill->count(),
                'numbers' => $toBill->take(3)->map(fn (PurchaseOrder $o) => $o->po_number)->all(),
                'url' => '/purchasing/purchase-orders?stage=to_bill',
            ],
        ];
    }

    /**
     * Expense vouchers between two dates, the reversed ones left out: total,
     * count, the five biggest heads, and the split by the account paid from.
     *
     * @return array<string, mixed>
     */
    public static function spend(Carbon $from, Carbon $to, string $label): array
    {
        $vouchers = ExpenseVoucher::query()->live()
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString())
            ->with('moneyAccount:id,name')
            ->get(['id', 'total', 'money_account_id']);

        $total = Money::sum($vouchers->map(fn (ExpenseVoucher $v) => Money::ofTaka((string) $v->total)));

        $heads = ExpenseVoucherLine::query()
            ->whereIn('expense_voucher_id', $vouchers->pluck('id'))
            ->join('accounts', 'accounts.id', '=', 'expense_voucher_lines.account_id')
            ->groupBy('accounts.id', 'accounts.name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get([DB::raw('accounts.name as name'), DB::raw('SUM(expense_voucher_lines.amount) as amount')]);

        $max = (float) ($heads->first()?->amount ?? 0);

        $byAccount = $vouchers->groupBy(fn (ExpenseVoucher $v) => $v->moneyAccount?->name ?? 'Unnamed account')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'minor' => Money::sum($group->map(fn (ExpenseVoucher $v) => Money::ofTaka((string) $v->total)))->minor,
            ])
            ->sortByDesc('minor')->values();

        return [
            'label' => $label,
            'total' => $total->format(),
            'count' => $vouchers->count(),
            'heads' => $heads->map(fn ($row) => [
                'name' => $row->name,
                'amount' => Money::ofTaka((string) $row->amount)->format(),
                'share' => $max > 0 ? (int) round((float) $row->amount * 100 / $max) : 0,
            ])->values()->all(),
            'paid_from' => $byAccount->map(fn (array $row) => [
                'name' => $row['name'],
                'percent' => $total->minor > 0 ? (int) round($row['minor'] * 100 / $total->minor) : 0,
            ])->all(),
            'url' => '/purchasing/expenses',
        ];
    }

    /**
     * The newest goods receipt lines, with the batch and expiry each created.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentDeliveries(Carbon $today, int $limit = 5): array
    {
        return GoodsReceiptLine::query()
            ->with([
                'goodsReceipt:id,purchase_order_id,received_at',
                'goodsReceipt.purchaseOrder:id,po_number',
                'purchaseOrderLine:id,product_id',
                'purchaseOrderLine.product:id,name,unit_of_measure_id,purchase_unit_id',
                'purchaseOrderLine.product.unitOfMeasure:id,abbreviation',
                'purchaseOrderLine.product.purchaseUnit:id,abbreviation',
                'stockMovement:id,batch_id',
                'stockMovement.batch:id,batch_number,expiry_date',
            ])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (GoodsReceiptLine $line) use ($today) {
                $product = $line->purchaseOrderLine?->product;
                $expires = $line->stockMovement?->batch?->expiry_date;

                return [
                    'id' => $line->id,
                    'received_on' => $line->goodsReceipt?->received_at?->timezone(config('app.timezone'))->toDateString(),
                    'item' => $product?->name,
                    'quantity' => (string) $line->quantity_received,
                    'unit' => $product?->purchaseUnit?->abbreviation ?? $product?->unitOfMeasure?->abbreviation,
                    'po_number' => $line->goodsReceipt?->purchaseOrder?->po_number,
                    'batch' => $line->stockMovement?->batch?->batch_number,
                    'expires_on' => $expires?->toDateString(),
                    'days_left' => $expires === null ? null : (int) $today->copy()->startOfDay()->diffInDays($expires->copy()->startOfDay(), false),
                    'url' => $line->goodsReceipt?->purchase_order_id ? '/purchasing/purchase-orders/'.$line->goodsReceipt->purchase_order_id : '/purchasing/purchase-orders',
                ];
            })
            ->values()
            ->all();
    }
}
