<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Purchasing\Support\ReceiptRegister;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Purchase returns (purchase redesign PR2, 18 Oct 2026; Tally's Rejection
 * Out): every return to a supplier as a list and a page, linked back to its
 * order, the deliveries its batches came from and the credit notes against it.
 *
 * ⚠ Reads only. A return is still recorded from its order
 * (PurchaseOrderController@returnGoods) and credited on the credit note page.
 */
class PurchaseReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $returns = PurchaseReturn::query()
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'returnedBy:id,name'])
            ->withCount('lines')
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(
                fn (Builder $q) => $q->whereHas('purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', "%{$search}%"))
                    ->orWhere('note', 'like', "%{$search}%")
                    ->when(ctype_digit(ltrim($search, '#')), fn (Builder $id) => $id->orWhere('purchase_returns.id', (int) ltrim($search, '#')))
            ))
            ->when($filters['supplier_id'], fn (Builder $query, string $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('supplier_id', (int) $id)))
            ->when($this->date($filters['from']), fn (Builder $query, string $from) => $query->whereDate('returned_at', '>=', $from))
            ->when($this->date($filters['to']), fn (Builder $query, string $to) => $query->whereDate('returned_at', '<=', $to))
            ->orderByDesc('returned_at')
            ->orderByDesc('id')
            ->paginate(PerPage::from())
            ->withQueryString();

        $returns->setCollection($returns->getCollection()->map(fn (PurchaseReturn $return) => [
            'id' => $return->id,
            'returned_on' => $return->returned_at?->toDateString(),
            'returned_by' => $return->returnedBy?->name,
            'note' => $return->note,
            'lines_count' => $return->lines_count,
            'value' => $return->invoiceableValue()->toTaka(),
            'credited' => $return->creditedTotal()->toTaka(),
            'order' => $return->purchaseOrder === null ? null : ['id' => $return->purchaseOrder->id, 'po_number' => $return->purchaseOrder->po_number],
            'supplier' => $return->purchaseOrder?->supplier === null ? null : ['id' => $return->purchaseOrder->supplier->id, 'name' => $return->purchaseOrder->supplier->name],
        ]));

        return Inertia::render('purchasing/returns/index', [
            'returns' => $returns,
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): Response
    {
        $purchaseReturn->load([
            'purchaseOrder:id,po_number,supplier_id,warehouse_id',
            'purchaseOrder.supplier:id,name',
            'purchaseOrder.warehouse:id,name',
            'returnedBy:id,name',
            'lines.purchaseOrderLine.product:id,name,sku,unit_of_measure_id,purchase_unit_id',
            'lines.purchaseOrderLine.product.unitOfMeasure:id,abbreviation',
            'lines.purchaseOrderLine.product.purchaseUnit:id,abbreviation',
            'lines.stockMovement:id,batch_id,quantity,unit_cost',
            'lines.stockMovement.batch:id,batch_number,expiry_date',
        ]);

        $user = $request->user();
        $batchIds = $purchaseReturn->lines->map(fn (PurchaseReturnLine $line) => $line->stockMovement?->batch_id)->filter()->unique()->values()->all();

        // The delivery each batch arrived on: the receipt line whose stock-in
        // movement created that batch.
        $receiptByBatch = GoodsReceiptLine::query()
            ->whereHas('stockMovement', fn (Builder $q) => $q->whereIn('batch_id', $batchIds))
            ->with(['stockMovement:id,batch_id', 'goodsReceipt:id,received_at,supplier_challan_number'])
            ->get(['id', 'goods_receipt_id', 'stock_movement_id'])
            ->mapWithKeys(fn (GoodsReceiptLine $line) => [$line->stockMovement->batch_id => [
                'id' => $line->goods_receipt_id,
                'received_on' => $line->goodsReceipt?->received_at?->toDateString(),
                'challan' => $line->goodsReceipt?->supplier_challan_number,
            ]]);

        $value = $purchaseReturn->invoiceableValue();
        $credited = $purchaseReturn->creditedTotal();

        return Inertia::render('purchasing/returns/show', [
            // ⭐ Corrections wave 1 (20 Oct 2026): Reverse (goods back into the
            // same batches at the same cost), and whether it was reversed.
            'corrections' => CorrectionScreen::for($purchaseReturn, app(PurchaseReturnActionPolicy::class), $user),
            'reversal' => $purchaseReturn->reversalSummary(),
            'purchaseReturn' => [
                'id' => $purchaseReturn->id,
                'returned_on' => $purchaseReturn->returned_at?->toDateString(),
                'returned_by' => $purchaseReturn->returnedBy?->name,
                'note' => $purchaseReturn->note,
                'value' => $value->toTaka(),
                'credited' => $credited->toTaka(),
                'order' => $purchaseReturn->purchaseOrder === null ? null : ['id' => $purchaseReturn->purchaseOrder->id, 'po_number' => $purchaseReturn->purchaseOrder->po_number],
                'supplier' => $purchaseReturn->purchaseOrder?->supplier === null ? null : ['id' => $purchaseReturn->purchaseOrder->supplier->id, 'name' => $purchaseReturn->purchaseOrder->supplier->name],
                'warehouse' => $purchaseReturn->purchaseOrder?->warehouse?->name,
            ],
            'lines' => $purchaseReturn->lines->map(function (PurchaseReturnLine $line) use ($receiptByBatch) {
                $product = $line->purchaseOrderLine?->product;
                $batch = $line->stockMovement?->batch;

                return [
                    'id' => $line->id,
                    'product' => $product?->name,
                    'sku' => $product?->sku,
                    'quantity_returned' => (string) $line->quantity_returned,
                    'unit' => $product?->purchaseUnit?->abbreviation ?? $product?->unitOfMeasure?->abbreviation,
                    'value' => $line->purchaseOrderLine === null ? null : $line->purchaseOrderLine->valueOf((string) $line->quantity_returned)->toTaka(),
                    'batch' => $batch === null ? null : [
                        'id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'expiry_date' => $batch->expiry_date?->toDateString(),
                    ],
                    'receipt' => $batch === null ? null : ($receiptByBatch[$batch->id] ?? null),
                ];
            })->values(),
            'credit_notes' => SupplierCreditNote::query()
                ->where('purchase_return_id', $purchaseReturn->id)
                ->orderBy('credit_date')
                ->get(['id', 'credit_note_number', 'credit_date', 'total', 'reversed_at'])
                ->map(fn (SupplierCreditNote $note) => [
                    'id' => $note->id,
                    'number' => $note->credit_note_number,
                    'date' => $note->credit_date?->toDateString(),
                    'total' => Money::ofTaka((string) $note->total)->toTaka(),
                    'reversed' => $note->reversed_at !== null,
                ])
                ->values(),
            // ⭐ Initial plan B9 (Joy's Q21): what a paid bill could not take,
            // kept as a supplier advance for the next purchase.
            'advances' => SupplierAdvance::query()
                ->where('purchase_return_id', $purchaseReturn->id)
                ->orderBy('id')
                ->get()
                ->map(fn (SupplierAdvance $advance) => [
                    'id' => $advance->id,
                    'number' => $advance->number,
                    'date' => $advance->paid_on?->toDateString(),
                    'amount' => $advance->money()->toTaka(),
                    'open' => $advance->open()->toTaka(),
                    'reversed' => $advance->isReversed(),
                ])
                ->values(),
            'journals' => $user->can('accounting.view') ? ReceiptRegister::journalsOf($purchaseReturn) : [],
            'can_view_journals' => $user->can('accounting.view'),
            'can_view_batches' => $user->can('inventory.reports.view'),
            // ⚠ Offered only while some of its value is still uncredited.
            'can_record_credit_note' => $user->can('purchasing.credit_note.record') && $value->minor > $credited->minor,
        ]);
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
