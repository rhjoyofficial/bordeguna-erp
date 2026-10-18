<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\ReceiptRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Receipt Notes (purchase redesign PR2, 18 Oct 2026; Tally's Receipt Note,
 * Alt+F9): every delivery as its own list and page. Until now a goods receipt
 * was visible only inside its purchase order.
 *
 * ⚠ Reads only. A receipt is still recorded from its order (the Receive goods
 * step, PurchaseOrderController@receive) or by Buy and receive; returned from
 * its order; costed on the Landed costs page; billed on the bill page. This
 * page links to each of them and back.
 */
class GoodsReceiptController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            'warehouse_id' => $request->string('warehouse_id')->toString(),
            'purchase_order_id' => $request->string('purchase_order_id')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            // open = something still to bill; billed = nothing left to bill.
            'billing' => $request->string('billing')->toString(),
        ];

        $query = GoodsReceipt::query()
            ->with([
                'purchaseOrder:id,po_number,supplier_id,warehouse_id',
                'purchaseOrder.supplier:id,name',
                'purchaseOrder.warehouse:id,name',
                'receivedBy:id,name',
                'lines.purchaseOrderLine',
            ])
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(
                fn (Builder $q) => $q->where('supplier_challan_number', 'like', "%{$search}%")
                    ->orWhereHas('purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', "%{$search}%"))
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $id) => $id->orWhere('goods_receipts.id', (int) ltrim($search, '#')))
            ))
            ->when($filters['supplier_id'], fn (Builder $query, string $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('supplier_id', (int) $id)))
            ->when($filters['warehouse_id'], fn (Builder $query, string $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('warehouse_id', (int) $id)))
            ->when($filters['purchase_order_id'], fn (Builder $query, string $id) => $query->where('purchase_order_id', (int) $id))
            ->when($this->date($filters['from']), fn (Builder $query, string $from) => $query->whereDate('received_at', '>=', $from))
            ->when($this->date($filters['to']), fn (Builder $query, string $to) => $query->whereDate('received_at', '<=', $to))
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $register = new ReceiptRegister;

        if (in_array($filters['billing'], ['open', 'billed'], true)) {
            // ⚠ The bill state is derived per receipt line (ReceiptBilling), not
            // a column, so this filter reads the matching receipts and pages
            // them by hand. A business's receipts number in the hundreds.
            $all = $query->get()->filter(function (GoodsReceipt $receipt) use ($register, $filters) {
                $state = $register->billState($receipt);

                return $filters['billing'] === 'billed' ? $state === 'billed' : $state !== 'billed';
            })->values();

            $page = max(1, $request->integer('page', 1));
            $receipts = new LengthAwarePaginator(
                $all->forPage($page, self::PER_PAGE)->values(),
                $all->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $receipts = $query->paginate(self::PER_PAGE)->withQueryString();
        }

        $receipts->setCollection($receipts->getCollection()->map(fn (GoodsReceipt $receipt) => $this->row($receipt, $register)));

        return Inertia::render('purchasing/receipts/index', [
            'receipts' => $receipts,
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'can_receive' => $request->user()->can('purchasing.receipt.record'),
        ]);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->load([
            'purchaseOrder:id,po_number,supplier_id,warehouse_id,status,order_date',
            'purchaseOrder.supplier:id,name,code',
            'purchaseOrder.warehouse:id,name',
            'receivedBy:id,name',
            'lines.purchaseOrderLine.product:id,name,sku,type,unit_of_measure_id,purchase_unit_id',
            'lines.purchaseOrderLine.product.unitOfMeasure:id,abbreviation',
            'lines.purchaseOrderLine.product.purchaseUnit:id,abbreviation',
            'lines.stockMovement:id,batch_id,quantity,unit_cost',
            'lines.stockMovement.batch:id,batch_number,manufactured_date,expiry_date,current_quantity,unit_cost,held_at',
            'landedCosts:id,goods_receipt_id,number,status,total_paisa,incurred_on',
            'landedCosts.approvalRequest',
        ]);

        $user = $request->user();
        $register = new ReceiptRegister;
        $order = $goodsReceipt->purchaseOrder;
        $lineIds = $goodsReceipt->lines->pluck('id')->all();
        $batchIds = $goodsReceipt->lines->map(fn (GoodsReceiptLine $line) => $line->stockMovement?->batch_id)->filter()->values()->all();

        $lines = $goodsReceipt->lines->map(function (GoodsReceiptLine $line) use ($register) {
            $product = $line->purchaseOrderLine?->product;
            $batch = $line->stockMovement?->batch;

            return [
                'id' => $line->id,
                'product' => $product?->name,
                'sku' => $product?->sku,
                'quantity_received' => (string) $line->quantity_received,
                // The unit the supplier invoices in, then the stock the batch holds.
                'unit' => $product?->purchaseUnit?->abbreviation ?? $product?->unitOfMeasure?->abbreviation,
                'stock_quantity' => $line->stockMovement === null ? null : (string) $line->stockMovement->quantity,
                'stock_unit' => $product?->unitOfMeasure?->abbreviation,
                'unit_cost' => $line->stockMovement === null ? null : (string) $line->stockMovement->unit_cost,
                'value' => ReceiptRegister::taka($register->lineValue($line)),
                'billable' => $register->billableOn($line),
                'batch' => $batch === null ? null : [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    // ⚠ toDateString(), never the date cast's UTC serialisation.
                    'manufactured_date' => $batch->manufactured_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'current_quantity' => (string) $batch->current_quantity,
                    // After landed costs, the batch cost may be above the receipt's.
                    'unit_cost' => (string) $batch->unit_cost,
                    'held' => $batch->isHeld(),
                ],
            ];
        })->values();

        // Bills that name this delivery's lines (every bill since 19 Oct 2026),
        // then the order's other bills, which may cover it without naming it.
        $billIds = SupplierBill::query()
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('goods_receipt_line_id', $lineIds))
            ->pluck('id')
            ->all();

        $bills = SupplierBill::query()
            ->where(fn (Builder $q) => $q->whereIn('id', $billIds)->orWhere('purchase_order_id', $goodsReceipt->purchase_order_id))
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get(['id', 'bill_number', 'bill_date', 'total', 'reversed_at'])
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                'reversed' => $bill->reversed_at !== null,
                'names_this_delivery' => in_array($bill->id, $billIds, true),
            ])
            ->values();

        // Goods from this delivery's batches that went back to the supplier.
        $returns = PurchaseReturnLine::query()
            ->whereHas('stockMovement', fn (Builder $q) => $q->whereIn('batch_id', $batchIds))
            ->with('purchaseReturn:id,returned_at')
            ->get(['id', 'purchase_return_id', 'quantity_returned', 'stock_movement_id'])
            ->groupBy('purchase_return_id')
            ->map(fn ($rows) => [
                'id' => (int) $rows->first()->purchase_return_id,
                'returned_on' => $rows->first()->purchaseReturn?->returned_at?->toDateString(),
                'quantity' => number_format($rows->sum(fn ($row) => PurchaseLineEntry::scaled((string) $row->quantity_returned)) / 10000, 4, '.', ''),
            ])
            ->values();

        $quick = QuickPurchase::query()->where('goods_receipt_id', $goodsReceipt->id)->first(['id']);
        $billState = $register->billState($goodsReceipt);

        return Inertia::render('purchasing/receipts/show', [
            'receipt' => [
                'id' => $goodsReceipt->id,
                // ⚠ Formatted in the app's Asia/Dhaka clock, as on the order page.
                'received_at' => $goodsReceipt->received_at?->format($goodsReceipt->received_at->format('H:i') === '00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
                'received_on' => $goodsReceipt->received_at?->toDateString(),
                'received_by' => $goodsReceipt->receivedBy?->name,
                'supplier_challan_number' => $goodsReceipt->supplier_challan_number,
                'backdate_reason' => $goodsReceipt->backdate_reason,
                'note' => $goodsReceipt->note,
                'value' => ReceiptRegister::taka($register->valueOf($goodsReceipt)),
                'bill_state' => $billState,
                'order' => $order === null ? null : [
                    'id' => $order->id,
                    'po_number' => $order->po_number,
                    'status' => $order->status?->value,
                ],
                'supplier' => $order?->supplier === null ? null : ['id' => $order->supplier->id, 'name' => $order->supplier->name],
                'warehouse' => $order?->warehouse === null ? null : ['id' => $order->warehouse->id, 'name' => $order->warehouse->name],
                'quick_purchase_id' => $quick?->id,
            ],
            'lines' => $lines,
            'landed_costs' => $goodsReceipt->landedCosts->map(fn ($lc) => [
                'id' => $lc->id,
                'number' => $lc->number,
                'status' => $lc->isAwaitingApproval() ? 'waiting' : $lc->status->value,
                'total' => ReceiptRegister::taka((int) $lc->total_paisa),
                'incurred_on' => $lc->incurred_on?->toDateString(),
            ])->values(),
            'bills' => $bills,
            'returns' => $returns,
            // ⚠ Journal numbers only for whoever may open the journal.
            'journals' => $user->can('accounting.view') ? ReceiptRegister::journalsOf($goodsReceipt) : [],
            'can_view_journals' => $user->can('accounting.view'),
            'can_view_batches' => $user->can('inventory.reports.view'),
            'can_view_landed_costs' => $user->can('purchasing.landed_cost.view'),
            'can_manage_landed_costs' => $user->can('purchasing.landed_cost.manage'),
            'can_record_bill' => $user->can('purchasing.bill.record') && $billState !== 'billed',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(GoodsReceipt $receipt, ReceiptRegister $register): array
    {
        $order = $receipt->purchaseOrder;

        return [
            'id' => $receipt->id,
            'received_on' => $receipt->received_at?->toDateString(),
            'supplier_challan_number' => $receipt->supplier_challan_number,
            'received_by' => $receipt->receivedBy?->name,
            'lines_count' => $receipt->lines->count(),
            'value' => ReceiptRegister::taka($register->valueOf($receipt)),
            'bill_state' => $register->billState($receipt),
            'order' => $order === null ? null : ['id' => $order->id, 'po_number' => $order->po_number],
            'supplier' => $order?->supplier === null ? null : ['id' => $order->supplier->id, 'name' => $order->supplier->name],
            'warehouse' => $order?->warehouse?->name,
        ];
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
