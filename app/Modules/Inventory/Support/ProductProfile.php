<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\BillOfMaterialLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCostAllocation;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierDiscountAllocation;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Support\Documents\DocumentLinks;
use App\Support\Traceability\BatchTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ ONE PRODUCT, EVERYTHING ABOUT IT (PR5, 18 Oct 2026).
 *
 * The header of the product page and its tabs other than the stock card:
 * where the stock is, what it cost, what we last paid and to whom, what it
 * sells for, the recipe it is made by, its batches, every purchase line and
 * every sale line, and how its cost moved. Reads only; each figure comes
 * from the table that owns it.
 *
 * ⚠ A tab the viewer may not read is null (purchases without
 * purchasing.reports.view, sales without sales.reports.view), not an empty
 * list that looks like "never bought".
 */
final class ProductProfile
{
    public function __construct(private readonly Product $product, private readonly ?User $viewer) {}

    /** @return list<array<string, mixed>> */
    public function byWarehouse(): array
    {
        return StockBatch::query()
            ->where('product_id', $this->product->id)
            ->where('status', StockBatchStatus::Active)
            ->with('warehouse:id,name')
            ->get()
            ->groupBy('warehouse_id')
            ->map(function (Collection $batches) {
                $quantity = $batches->sum(fn (StockBatch $b) => (float) $b->current_quantity);
                $value = Money::sum($batches->map(fn (StockBatch $b) => $b->value()));

                return [
                    'warehouse_id' => $batches->first()->warehouse_id,
                    'warehouse' => $batches->first()->warehouse?->name,
                    'quantity' => BatchTrace::qty($quantity),
                    'value' => $value->format(),
                ];
            })
            ->filter(fn (array $row) => (float) $row['quantity'] !== 0.0)
            ->sortBy('warehouse')
            ->values()
            ->all();
    }

    /** What we paid last time, from the latest receipt line (the price on its order line). */
    public function lastPurchase(): ?array
    {
        if (! $this->sees('purchasing.reports.view')) {
            return null;
        }

        $line = GoodsReceiptLine::query()
            ->whereHas('purchaseOrderLine', fn ($q) => $q->where('product_id', $this->product->id))
            ->with(['goodsReceipt.purchaseOrder.supplier:id,name', 'purchaseOrderLine'])
            ->get()
            ->sortByDesc(fn (GoodsReceiptLine $l) => [$l->goodsReceipt?->received_at?->toDateString(), $l->id])
            ->first();

        if ($line === null) {
            return null;
        }

        $receipt = $line->goodsReceipt;

        return [
            'rate' => Money::ofTaka((string) $line->purchaseOrderLine?->unit_cost)->format(),
            'date' => $receipt?->received_at?->toDateString(),
            'supplier' => $receipt?->purchaseOrder?->supplier === null ? null : DocumentLinks::ref($receipt->purchaseOrder->supplier, $this->viewer) + ['name' => $receipt->purchaseOrder->supplier->name],
            'document' => DocumentLinks::ref($receipt, $this->viewer),
        ];
    }

    /** MRP and every general price rule in force today (customer-specific prices stay on the prices page). */
    public function sellingPrices(): array
    {
        $rules = ProductPrice::query()
            ->where('product_id', $this->product->id)
            ->whereNull('customer_id')
            ->inForceOn(Carbon::today())
            ->with('priceGroup:id,name')
            ->orderBy('price_group_id')
            ->orderBy('min_quantity')
            ->get()
            ->map(fn (ProductPrice $price) => [
                'group' => $price->priceGroup?->name,
                'min_quantity' => BatchTrace::qty((float) $price->min_quantity),
                'price' => $price->unit_price === null ? null : Money::ofTaka((string) $price->unit_price)->format(),
                'label' => $price->is_discount ? $price->discount_label : null,
            ])
            ->values()
            ->all();

        return [
            'mrp' => $this->product->mrp === null ? null : Money::ofTaka((string) $this->product->mrp)->format(),
            'rules' => $rules,
        ];
    }

    /** The active recipe that makes this product, if any. */
    public function recipe(): ?array
    {
        $bom = BillOfMaterial::query()
            ->where('output_product_id', $this->product->id)
            ->where('is_active', true)
            ->with(['lines.componentProduct:id,name,sku,unit_of_measure_id', 'lines.componentProduct.unitOfMeasure:id,abbreviation'])
            ->orderByDesc('version')
            ->first();

        if ($bom === null) {
            return null;
        }

        return [
            'id' => $bom->id,
            'version' => $bom->version,
            'name' => $bom->name,
            'output_quantity' => BatchTrace::qty((float) $bom->output_quantity),
            'lines' => $bom->lines->map(fn (BillOfMaterialLine $line) => [
                'product_id' => $line->component_product_id,
                'product' => $line->componentProduct?->name,
                'sku' => $line->componentProduct?->sku,
                'quantity' => BatchTrace::qty((float) $line->quantity_per_output),
                'unit' => $line->componentProduct?->unitOfMeasure?->abbreviation,
                'wastage_percent' => $line->wastage_percent === null ? null : BatchTrace::qty((float) $line->wastage_percent),
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>>|null */
    public function suppliers(): ?array
    {
        if (! $this->sees('purchasing.reports.view')) {
            return null;
        }

        return PurchaseOrderLine::query()
            ->where('product_id', $this->product->id)
            ->with('purchaseOrder.supplier:id,name')
            ->get()
            ->filter(fn (PurchaseOrderLine $line) => $line->purchaseOrder?->supplier !== null)
            ->groupBy(fn (PurchaseOrderLine $line) => $line->purchaseOrder->supplier_id)
            ->map(function (Collection $lines) {
                $latest = $lines->sortByDesc(fn (PurchaseOrderLine $l) => [$l->purchaseOrder->orderDateString(), $l->id])->first();
                $supplier = $latest->purchaseOrder->supplier;

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'url' => DocumentLinks::url($supplier, $this->viewer),
                    'orders' => $lines->pluck('purchase_order_id')->unique()->count(),
                    'last_rate' => Money::ofTaka((string) $latest->unit_cost)->format(),
                    'last_date' => $latest->purchaseOrder->orderDateString(),
                ];
            })
            ->sortByDesc('last_date')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function batches(): array
    {
        return StockBatch::query()
            ->where('product_id', $this->product->id)
            ->with('warehouse:id,name')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (StockBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'warehouse' => $batch->warehouse?->name,
                'received_at' => $batch->received_at?->toDateString(),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'received' => BatchTrace::qty((float) $batch->initial_quantity),
                'on_hand' => BatchTrace::qty((float) $batch->current_quantity),
                'unit_cost' => $batch->unit_cost === null ? null : Money::ofTaka((string) $batch->unit_cost)->format(),
                'status' => $batch->status->value,
                'held' => $batch->isHeld(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every purchase order line for the product, newest first, with what the
     * receipts against it took off (discounts) and put on (landed costs).
     *
     * @return list<array<string, mixed>>|null
     */
    public function purchases(): ?array
    {
        if (! $this->sees('purchasing.reports.view')) {
            return null;
        }

        $lines = PurchaseOrderLine::query()
            ->where('product_id', $this->product->id)
            ->with('purchaseOrder.supplier:id,name')
            ->get();

        $receiptLines = GoodsReceiptLine::query()->whereIn('purchase_order_line_id', $lines->pluck('id'))->get(['id', 'purchase_order_line_id']);
        $lineOf = $receiptLines->pluck('purchase_order_line_id', 'id');

        $discounts = SupplierDiscountAllocation::query()
            ->whereIn('goods_receipt_line_id', $receiptLines->pluck('id'))
            ->whereHas('discount', fn ($q) => $q->whereNull('reversed_at'))
            ->get(['goods_receipt_line_id', 'share_paisa'])
            ->groupBy(fn ($a) => $lineOf[$a->goods_receipt_line_id] ?? 0)
            ->map(fn (Collection $rows) => (int) $rows->sum('share_paisa'));

        $landed = LandedCostAllocation::query()
            ->whereIn('goods_receipt_line_id', $receiptLines->pluck('id'))
            ->get(['goods_receipt_line_id', 'share_paisa'])
            ->groupBy(fn ($a) => $lineOf[$a->goods_receipt_line_id] ?? 0)
            ->map(fn (Collection $rows) => (int) $rows->sum('share_paisa'));

        return $lines
            ->sortByDesc(fn (PurchaseOrderLine $l) => [$l->purchaseOrder?->orderDateString(), $l->id])
            ->map(function (PurchaseOrderLine $line) use ($discounts, $landed) {
                $order = $line->purchaseOrder;
                $discount = (int) ($discounts[$line->id] ?? 0);
                $landedPaisa = (int) ($landed[$line->id] ?? 0);
                $received = (float) $line->quantity_received;
                $base = Money::ofProduct((string) $line->quantity_received, (string) $line->unit_cost)->minor;

                return [
                    'id' => $line->id,
                    'date' => $order?->orderDateString(),
                    'document' => $order === null ? null : DocumentLinks::ref($order, $this->viewer),
                    'supplier' => $order?->supplier?->name,
                    'supplier_url' => $order?->supplier === null ? null : DocumentLinks::url($order->supplier, $this->viewer),
                    'ordered' => BatchTrace::qty((float) $line->quantity_ordered),
                    'received' => BatchTrace::qty($received),
                    'rate' => Money::ofTaka((string) $line->unit_cost)->format(),
                    'discount' => $discount === 0 ? null : Money::ofMinor($discount)->format(),
                    'landed' => $landedPaisa === 0 ? null : Money::ofMinor($landedPaisa)->format(),
                    // What one unit cost once the discount came off and the landed cost went on.
                    'landed_unit_cost' => $received > 0 ? Money::ofMinor((int) round(($base - $discount + $landedPaisa) / $received))->format() : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>>|null */
    public function sales(): ?array
    {
        if (! $this->sees('sales.reports.view')) {
            return null;
        }

        return SalesInvoiceLine::query()
            ->where('product_id', $this->product->id)
            // ⭐ Corrections wave 1: a credited invoice sold nothing.
            ->whereHas('invoice', fn ($invoice) => $invoice->whereNull('reversed_at'))
            ->with('invoice.customer:id,name')
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (SalesInvoiceLine $line) => $line->invoice !== null)
            ->sortByDesc(fn (SalesInvoiceLine $l) => [$l->invoice->invoice_date?->toDateString(), $l->id])
            ->map(fn (SalesInvoiceLine $line) => [
                'id' => $line->id,
                'date' => $line->invoice->invoice_date?->toDateString(),
                'document' => DocumentLinks::ref($line->invoice, $this->viewer),
                'customer' => $line->invoice->customer?->name,
                'customer_url' => $line->invoice->customer === null ? null : DocumentLinks::url($line->invoice->customer, $this->viewer),
                'quantity' => BatchTrace::qty((float) $line->quantity),
                'price' => Money::ofTaka((string) $line->unit_price)->format(),
                'total' => $line->line_net === null ? null : Money::ofTaka((string) $line->line_net)->format(),
            ])
            ->values()
            ->all();
    }

    /**
     * How the unit cost moved: every batch that came in (its cost), and every
     * value-only movement after it (landed cost, discount, revaluation).
     *
     * @return list<array<string, mixed>>
     */
    public function costHistory(): array
    {
        $movements = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where(fn ($q) => $q
                ->where(fn ($in) => $in->where('direction', MovementDirection::In)->where('quantity', '>', 0)->whereNull('transfer_group_id'))
                ->orWhere('quantity', 0))
            ->whereNull('reverses_movement_id')
            ->with(['batch:id,batch_number,unit_cost', 'warehouse:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $documents = ProductStockCard::documentsFor($movements);

        return $movements->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'date' => $m->occurred_at?->toDateString(),
            'reason' => $m->reason->value,
            'batch_id' => $m->batch_id,
            'batch_number' => $m->batch?->batch_number,
            'warehouse' => $m->warehouse?->name,
            'unit_cost' => (float) $m->quantity > 0 && $m->unit_cost !== null ? Money::ofTaka((string) $m->unit_cost)->format() : null,
            'change' => (float) $m->quantity === 0.0 ? StockValue::of($m)->format() : null,
            'document' => isset($documents[$m->id]) ? DocumentLinks::ref($documents[$m->id]['model'], $this->viewer) : null,
        ])->values()->all();
    }

    private function sees(string $ability): bool
    {
        return $this->viewer === null || $this->viewer->can($ability);
    }
}
