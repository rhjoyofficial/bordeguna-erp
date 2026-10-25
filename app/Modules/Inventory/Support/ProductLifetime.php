<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\BillOfMaterialLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\ProductionRunLine;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Sales\Models\DeliveryLineMovement;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use App\Support\Documents\DocumentLinks;
use App\Support\Lifetime\LifetimeRange;
use App\Support\Traceability\BatchTrace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ B23 LIFETIME OF ONE PRODUCT (25 Oct 2026; R13, R76, R77).
 *
 * Joy: "by each product its life time all the costing, every money related
 * things, its supplier related things, customer related things will come."
 * ProductProfile already lists the lines (purchases, sales, batches, cost
 * history); this adds what those lists never said in one place:
 *
 *   totals      bought, made, used, sold, returned, written off (by reason),
 *               moved, opening stock, with quantity and cost value, from ONE
 *               grouped read of the stock movements
 *   money       revenue (sales invoices), the cost of what was sold, what
 *               customers' returns gave back, and the margin
 *   bySupplier  bought per supplier (received quantity and value)
 *   runs        production runs that MADE it
 *   usedIn      runs that CONSUMED it, and the active recipes that call for it
 *   batches     profit per batch: where each batch came from (a supplier's
 *               receipt or a run), its cost, what of it was sold, the revenue
 *               those units earned, and the margin
 *
 * ⚠⚠ A REVERSAL COUNTS AGAINST WHAT IT UNDOES. A receipt correction is its
 * own reason on the movement, but it names the receipt movement it reverses
 * (`reverses_movement_id`), so it is grouped under "bought" with a minus sign;
 * likewise a challan reversed puts its units back under "sold". Lifetime
 * totals are what really happened, not what was typed and taken back.
 *
 * ⚠ Opening stock is written as adjustments tagged `opening_stock_id`; it is
 * its own line here, never "found stock".
 *
 * ⚠ FIXED QUERY COUNT. Every part is a bounded number of reads whatever the
 * number of batches, movements or sales (ProductLifetimeTest counts them),
 * because a product sold ten thousand times must open as fast as a new one.
 *
 * ⚠ Permissions: revenue and margin need the sales cost reports
 * (`sales.reports.view` + `sales.reports.costs`, null otherwise); the
 * per-supplier split needs `purchasing.reports.view`. Quantities and stock
 * cost follow the product page itself (its cost history is already there).
 */
final class ProductLifetime
{
    /** How many rows a lifetime list carries; the rest is in the export. */
    public const LIST_LIMIT = 200;

    /** @var list<array<string, mixed>>|null read once, used by totals() and money() */
    private ?array $totals = null;

    public function __construct(
        private readonly Product $product,
        private readonly ?User $viewer,
        private readonly LifetimeRange $range,
    ) {}

    /**
     * Quantity and cost value per kind of movement, signed so a reversal
     * takes away from what it undoes.
     *
     * @return list<array{key: string, reason: string, direction: string, value_only: bool, quantity: string, value: string, label_reason: ?string}>
     */
    public function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }

        $rows = $this->movementQuery()
            ->selectRaw(
                "CASE WHEN COALESCE(o.opening_stock_id, m.opening_stock_id) IS NOT NULL THEN 'opening' ELSE COALESCE(o.reason, m.reason) END AS kind,
                 COALESCE(o.direction, m.direction) AS dir,
                 CASE WHEN m.quantity = 0 THEN 1 ELSE 0 END AS value_only,
                 SUM(CASE WHEN o.id IS NULL THEN m.quantity ELSE -m.quantity END) AS qty,
                 SUM((CASE WHEN o.id IS NULL THEN 1 ELSE -1 END)
                     * (CASE WHEN m.value_delta IS NOT NULL THEN ABS(m.value_delta) ELSE m.quantity * COALESCE(m.unit_cost, 0) END)) AS val"
            )
            ->groupBy('kind', 'dir', 'value_only')
            ->get();

        return $this->totals = $rows
            ->map(fn ($row) => [
                'key' => $row->kind.'_'.$row->dir.((int) $row->value_only === 1 ? '_value' : ''),
                'reason' => (string) $row->kind,
                'direction' => (string) $row->dir,
                'value_only' => (int) $row->value_only === 1,
                'quantity' => BatchTrace::qty((float) $row->qty),
                'value' => self::amount($row->val)->format(),
            ])
            ->filter(fn (array $row) => (float) $row['quantity'] !== 0.0 || self::amount($row['value'])->minor !== 0)
            ->sortBy(fn (array $row) => array_search($row['key'], self::ORDER, true) === false ? 999 : array_search($row['key'], self::ORDER, true))
            ->values()
            ->all();
    }

    /** The order a reader meets the totals in: in, then out, then value-only. */
    private const ORDER = [
        'opening_in', 'receipt_in', 'harvest_in', 'production_in', 'sales_return_in', 'adjustment_in',
        'sale_out', 'production_out', 'purchase_return_out', 'adjustment_out', 'usage_out', 'owner_withdrawal_out', 'sample_out',
        'staff_replacement_out', 'transfer_out', 'transfer_in',
        'landed_cost_in_value', 'purchase_discount_out_value', 'adjustment_in_value', 'adjustment_out_value',
    ];

    /**
     * Stock written off, by the reason the person chose (damaged, expired,
     * lost, a count's shortfall). Opening stock is not a write-off.
     *
     * @return list<array{reason: string, quantity: string, value: string}>
     */
    public function writeOffs(): array
    {
        return $this->movementQuery()
            ->leftJoin('reasons as r', 'r.id', '=', DB::raw('COALESCE(o.reason_id, m.reason_id)'))
            ->whereRaw('COALESCE(o.reason, m.reason) = ?', [MovementReason::Adjustment->value])
            ->whereRaw('COALESCE(o.direction, m.direction) = ?', [MovementDirection::Out->value])
            ->whereRaw('COALESCE(o.opening_stock_id, m.opening_stock_id) IS NULL')
            ->where('m.quantity', '<>', 0)
            ->selectRaw(
                "COALESCE(r.name, 'Other') AS reason_name,
                 SUM(CASE WHEN o.id IS NULL THEN m.quantity ELSE -m.quantity END) AS qty,
                 SUM((CASE WHEN o.id IS NULL THEN 1 ELSE -1 END) * m.quantity * COALESCE(m.unit_cost, 0)) AS val"
            )
            ->groupBy('reason_name')
            ->orderBy('reason_name')
            ->get()
            ->map(fn ($row) => [
                'reason' => (string) $row->reason_name,
                'quantity' => BatchTrace::qty((float) $row->qty),
                'value' => self::amount($row->val)->format(),
            ])
            ->filter(fn (array $row) => (float) $row['quantity'] !== 0.0)
            ->values()
            ->all();
    }

    /**
     * Revenue, the cost of what was sold, what returns gave back, and the
     * margin. Null for a reader who may not see sales costs.
     *
     * Revenue is the net of every sales invoice line for the product on an
     * invoice that stands; the cost of sales is what the challans took out at
     * batch cost (net of reversed challans); a return takes back its credit
     * and the cost it restored.
     *
     * @return array<string, string|null>|null
     */
    public function money(): ?array
    {
        if (! $this->seesMargin()) {
            return null;
        }

        $invoiced = SalesInvoiceLine::query()
            ->where('product_id', $this->product->id)
            ->whereHas('invoice', fn ($invoice) => $this->range->apply($invoice->whereNull('reversed_at'), 'invoice_date'))
            ->selectRaw('COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(line_net), 0) AS net')
            ->first();

        $sold = collect($this->totals())->firstWhere('key', 'sale_out');

        $returns = DB::table('sales_return_lines as rl')
            ->join('sales_returns as sr', 'sr.id', '=', 'rl.sales_return_id')
            ->join('delivery_lines as dl', 'dl.id', '=', 'rl.delivery_line_id')
            ->where('dl.product_id', $this->product->id)
            ->where('sr.business_id', $this->product->business_id)
            ->when($this->range->from !== null, fn ($q) => $q->where('sr.returned_at', '>=', $this->range->from->toDateString()))
            ->when($this->range->to !== null, fn ($q) => $q->where('sr.returned_at', '<=', $this->range->to->toDateString()))
            ->selectRaw('COALESCE(SUM(rl.quantity), 0) AS qty, COALESCE(SUM(rl.credit_net), 0) AS credit, COALESCE(SUM(rl.cogs_relief), 0) AS cost')
            ->first();

        $revenue = self::amount($invoiced?->net ?? 0);
        $cogs = self::amount($sold['value'] ?? '0');
        $credited = self::amount($returns?->credit ?? 0);
        $costBack = self::amount($returns?->cost ?? 0);
        $netRevenue = $revenue->plus($credited->negated());
        $netCost = $cogs->plus($costBack->negated());
        $margin = $netRevenue->plus($netCost->negated());

        return [
            'invoiced_quantity' => BatchTrace::qty((float) ($invoiced?->qty ?? 0)),
            'revenue' => $revenue->format(),
            'returned_quantity' => BatchTrace::qty((float) ($returns?->qty ?? 0)),
            'returns_credit' => $credited->format(),
            'cost_of_sales' => $cogs->format(),
            'returns_cost' => $costBack->format(),
            'net_revenue' => $netRevenue->format(),
            'net_cost' => $netCost->format(),
            'margin' => $margin->format(),
            'margin_percent' => $netRevenue->minor > 0 ? number_format($margin->minor * 100 / $netRevenue->minor, 1, '.', '') : null,
        ];
    }

    /**
     * Bought, per supplier: received quantity and value at the order price.
     * Null for a reader who may not see purchasing.
     *
     * @return list<array<string, mixed>>|null
     */
    public function bySupplier(): ?array
    {
        if (! $this->sees('purchasing.reports.view')) {
            return null;
        }

        $lines = GoodsReceiptLine::query()
            ->whereHas('purchaseOrderLine', fn ($q) => $q->where('product_id', $this->product->id))
            ->whereHas('goodsReceipt', fn ($q) => $this->range->apply($q, 'received_at'))
            ->with(['goodsReceipt:id,purchase_order_id,received_at', 'goodsReceipt.purchaseOrder:id,supplier_id', 'goodsReceipt.purchaseOrder.supplier:id,name,code', 'purchaseOrderLine:id,unit_cost'])
            ->get();

        return $lines
            ->filter(fn (GoodsReceiptLine $line) => $line->goodsReceipt?->purchaseOrder?->supplier !== null)
            ->groupBy(fn (GoodsReceiptLine $line) => $line->goodsReceipt->purchaseOrder->supplier_id)
            ->map(function (Collection $group) {
                $supplier = $group->first()->goodsReceipt->purchaseOrder->supplier;
                $quantity = $group->sum(fn (GoodsReceiptLine $line) => (float) $line->quantity_received);
                $value = Money::sum($group->map(fn (GoodsReceiptLine $line) => Money::ofProduct((string) $line->quantity_received, (string) ($line->purchaseOrderLine?->unit_cost ?? '0'))));

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'url' => DocumentLinks::url($supplier, $this->viewer),
                    'receipts' => $group->pluck('goods_receipt_id')->unique()->count(),
                    'quantity' => BatchTrace::qty($quantity),
                    'value' => $value->format(),
                    'average_rate' => $quantity > 0 ? Money::ofMinor((int) round($value->minor / $quantity))->format() : null,
                    'last_date' => $group->map(fn (GoodsReceiptLine $line) => $line->goodsReceipt->received_at?->toDateString())->filter()->max(),
                ];
            })
            ->sortByDesc(fn (array $row) => self::amount($row['value'])->minor)
            ->values()
            ->all();
    }

    /**
     * Production runs that made this product, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        return $this->range->apply(ProductionRun::query(), 'occurred_at')
            ->where('output_product_id', $this->product->id)
            ->where('status', '<>', ProductionRunStatus::Draft->value)
            ->with('outputBatch:id,batch_number')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (ProductionRun $run) => [
                'id' => $run->id,
                'document' => DocumentLinks::ref($run, $this->viewer),
                'date' => $run->occurred_at?->toDateString(),
                'status' => $run->status->value,
                'quantity' => BatchTrace::qty((float) $run->output_quantity),
                'value' => $run->output_value === null ? null : Money::ofTaka((string) $run->output_value)->format(),
                'unit_cost' => $run->output_value !== null && (float) $run->output_quantity > 0
                    ? Money::ofMinor((int) round(Money::ofTaka((string) $run->output_value)->minor / (float) $run->output_quantity))->format()
                    : null,
                'batch_id' => $run->output_batch_id,
                'batch_number' => $run->outputBatch?->batch_number,
            ])
            ->values()
            ->all();
    }

    /**
     * Where this product goes in: the runs that consumed it, and the active
     * recipes that call for it.
     *
     * @return array{runs: list<array<string, mixed>>, recipes: list<array<string, mixed>>}
     */
    public function usedIn(): array
    {
        $lines = ProductionRunLine::query()
            ->where('product_id', $this->product->id)
            ->whereHas('run', fn ($q) => $this->range->apply($q->where('status', '<>', ProductionRunStatus::Draft->value), 'occurred_at'))
            ->with(['run:id,run_number,status,occurred_at,output_product_id,output_quantity', 'run.outputProduct:id,name,sku'])
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $recipes = BillOfMaterialLine::query()
            ->where('component_product_id', $this->product->id)
            ->whereHas('billOfMaterial', fn ($q) => $q->where('is_active', true))
            ->with(['billOfMaterial:id,name,version,output_product_id,output_quantity', 'billOfMaterial.outputProduct:id,name,sku'])
            ->get();

        return [
            'runs' => $lines
                ->sortByDesc(fn (ProductionRunLine $line) => [$line->run?->occurred_at?->toDateString(), $line->id])
                ->map(fn (ProductionRunLine $line) => [
                    'id' => $line->id,
                    'document' => DocumentLinks::ref($line->run, $this->viewer),
                    'date' => $line->run?->occurred_at?->toDateString(),
                    'status' => $line->run?->status?->value,
                    'output_product_id' => $line->run?->output_product_id,
                    'output_product' => $line->run?->outputProduct?->name,
                    'quantity' => BatchTrace::qty((float) $line->quantity_consumed),
                    'value' => $line->consumed_value === null ? null : Money::ofTaka((string) $line->consumed_value)->format(),
                ])
                ->values()
                ->all(),
            'recipes' => $recipes
                ->map(fn (BillOfMaterialLine $line) => [
                    'id' => $line->id,
                    'recipe_id' => $line->bill_of_material_id,
                    'name' => $line->billOfMaterial?->name,
                    'version' => $line->billOfMaterial?->version,
                    'output_product_id' => $line->billOfMaterial?->output_product_id,
                    'output_product' => $line->billOfMaterial?->outputProduct?->name,
                    'quantity_per_output' => BatchTrace::qty((float) $line->quantity_per_output),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * ⭐ R13: profit or loss per batch. A batch moved to another warehouse is
     * counted with the batch it came from (source_batch_id), so a lot bought
     * once and sold from two shops is one row.
     *
     * Per batch: where it came from, its cost, what of it went to customers
     * (challans that stand, less what came back), what those units cost, the
     * revenue they earned (each challan line's invoice price) and the margin.
     * Revenue and margin are null for a reader who may not see sales costs.
     *
     * @return list<array<string, mixed>>
     */
    public function batchProfit(): array
    {
        $batches = $this->range->apply(StockBatch::query(), 'received_at')
            ->where('product_id', $this->product->id)
            ->with('warehouse:id,name')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        if ($batches->isEmpty()) {
            return [];
        }

        // Every batch a transfer made, back to the batch it started as.
        $byId = $batches->keyBy('id');
        $origin = function (StockBatch $batch) use ($byId): int {
            $seen = [];
            $current = $batch;

            while ($current->source_batch_id !== null && $byId->has($current->source_batch_id) && ! isset($seen[$current->id])) {
                $seen[$current->id] = true;
                $current = $byId->get($current->source_batch_id);
            }

            return (int) $current->id;
        };
        $originOf = $batches->mapWithKeys(fn (StockBatch $batch) => [$batch->id => $origin($batch)]);
        $origins = $batches->filter(fn (StockBatch $batch) => $originOf[$batch->id] === (int) $batch->id)->take(self::LIST_LIMIT);

        // Where each origin came from: its first "in" movement and the document behind it.
        $firstIns = StockMovement::query()
            ->whereIn('batch_id', $origins->pluck('id'))
            ->where('direction', MovementDirection::In)
            ->where('quantity', '>', 0)
            ->whereNull('reverses_movement_id')
            ->orderBy('id')
            ->get(['id', 'batch_id', 'reason', 'opening_stock_id', 'production_run_id', 'reverses_movement_id'])
            ->unique('batch_id')
            ->keyBy('batch_id');
        $documents = ProductStockCard::documentsFor($firstIns->values());

        // The supplier behind a receipt.
        $receiptIds = collect($documents)->map(fn (array $found) => $found['model'])->filter(fn ($model) => $model instanceof GoodsReceipt)->map->getKey()->values();
        $suppliers = GoodsReceipt::query()->whereIn('id', $receiptIds)->with('purchaseOrder.supplier:id,name')->get()
            ->mapWithKeys(fn (GoodsReceipt $receipt) => [$receipt->id => $receipt->purchaseOrder?->supplier]);

        // What went to customers, from every batch of each family.
        $sales = StockMovement::query()
            ->whereIn('batch_id', $batches->pluck('id'))
            ->where('direction', MovementDirection::Out)
            ->where('reason', MovementReason::Sale)
            ->whereNull('reverses_movement_id')
            ->get(['id', 'batch_id', 'quantity', 'unit_cost']);

        $links = DeliveryLineMovement::query()
            ->whereIn('stock_movement_id', $sales->pluck('id'))
            ->whereHas('deliveryLine.delivery', fn ($delivery) => $delivery->whereNull('reversed_at'))
            ->get(['stock_movement_id', 'delivery_line_id'])
            ->keyBy('stock_movement_id');

        $prices = $this->seesMargin()
            ? SalesInvoiceLine::query()
                ->whereIn('delivery_line_id', $links->pluck('delivery_line_id')->unique())
                ->whereHas('invoice', fn ($invoice) => $invoice->whereNull('reversed_at'))
                ->get(['delivery_line_id', 'quantity', 'line_net', 'unit_price'])
                ->groupBy('delivery_line_id')
                ->map(function (Collection $lines) {
                    $quantity = $lines->sum(fn ($line) => (float) $line->quantity);
                    $net = Money::sum($lines->map(fn ($line) => $line->line_net !== null ? Money::ofTaka((string) $line->line_net) : Money::ofProduct((string) $line->quantity, (string) $line->unit_price)));

                    return $quantity > 0 ? $net->minor / $quantity : 0.0;
                })
            : collect();

        $returned = SalesReturnLineMovement::query()
            ->whereIn('stock_movement_id', $sales->pluck('id'))
            ->get(['stock_movement_id', 'quantity', 'unit_cost'])
            ->groupBy('stock_movement_id');

        $perOrigin = [];

        foreach ($sales as $sale) {
            $link = $links->get($sale->id);

            if ($link === null) {
                continue; // a reversed challan: the units went back onto the batch
            }

            $back = (float) ($returned->get($sale->id)?->sum(fn ($r) => (float) $r->quantity) ?? 0);
            $quantity = max(0.0, (float) $sale->quantity - $back);
            $key = $originOf[$sale->batch_id] ?? (int) $sale->batch_id;
            $perOrigin[$key] ??= ['quantity' => 0.0, 'cost' => 0, 'revenue' => 0.0];
            $perOrigin[$key]['quantity'] += $quantity;
            $perOrigin[$key]['cost'] += Money::ofProduct(number_format($quantity, 4, '.', ''), (string) ($sale->unit_cost ?? '0'))->minor;
            $perOrigin[$key]['revenue'] += $quantity * (float) ($prices->get($link->delivery_line_id) ?? 0);
        }

        $family = $batches->groupBy(fn (StockBatch $batch) => $originOf[$batch->id]);
        $seesMargin = $this->seesMargin();

        return $origins->map(function (StockBatch $batch) use ($firstIns, $documents, $suppliers, $perOrigin, $family, $seesMargin) {
            $in = $firstIns->get($batch->id);
            $document = $in === null ? null : ($documents[$in->id]['model'] ?? null);
            // The supplier's name only to a reader of purchasing, as on the rest of the page.
            $supplier = $document instanceof GoodsReceipt && $this->sees('purchasing.reports.view') ? $suppliers->get($document->getKey()) : null;
            $sold = $perOrigin[$batch->id] ?? ['quantity' => 0.0, 'cost' => 0, 'revenue' => 0.0];
            $revenue = Money::ofMinor((int) round($sold['revenue']));
            $members = $family->get($batch->id) ?? collect([$batch]);

            return [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'received_at' => $batch->received_at?->toDateString(),
                'warehouse' => $batch->warehouse?->name,
                'source' => $in === null ? null : ($in->opening_stock_id !== null ? 'opening' : $in->reason->value),
                'document' => $document === null ? null : DocumentLinks::ref($document, $this->viewer),
                'supplier' => $supplier === null ? null : ['name' => $supplier->name, 'url' => DocumentLinks::url($supplier, $this->viewer)],
                'received' => BatchTrace::qty((float) $batch->initial_quantity),
                'unit_cost' => $batch->unit_cost === null ? null : Money::ofTaka((string) $batch->unit_cost)->format(),
                'cost' => Money::ofProduct((string) $batch->initial_quantity, (string) ($batch->unit_cost ?? '0'))->format(),
                'on_hand' => BatchTrace::qty($members->sum(fn (StockBatch $b) => (float) $b->current_quantity)),
                'sold' => BatchTrace::qty($sold['quantity']),
                'cost_of_sold' => Money::ofMinor($sold['cost'])->format(),
                'revenue' => $seesMargin ? $revenue->format() : null,
                'margin' => $seesMargin ? Money::ofMinor($revenue->minor - $sold['cost'])->format() : null,
            ];
        })->values()->all();
    }

    public function seesMargin(): bool
    {
        return $this->sees('sales.reports.view') && $this->sees('sales.reports.costs');
    }

    /** The product's movements in the window, each beside the one it reverses (alias o). */
    private function movementQuery()
    {
        $query = DB::table('stock_movements as m')
            // ⚠ Only a true undo joins its original: the same reason (a challan
            // reversed, a run reversed, an adjustment reversed) or a receipt
            // correction. A customer's return or a return to the supplier also
            // names the movement it came from, but it is its own event.
            ->leftJoin('stock_movements as o', fn ($join) => $join->on('o.id', '=', 'm.reverses_movement_id')
                ->where(fn ($same) => $same->whereColumn('m.reason', 'o.reason')
                    ->orWhere('m.reason', MovementReason::ReceiptCorrection->value)))
            ->where('m.business_id', $this->product->business_id)
            ->where('m.product_id', $this->product->id);

        return $this->range->apply($query, 'm.occurred_at');
    }

    private static function amount(mixed $value): Money
    {
        $text = is_string($value) ? str_replace(',', '', $value) : number_format((float) $value, 4, '.', '');

        return Money::ofTaka($text === '' ? '0' : $text);
    }

    private function sees(string $ability): bool
    {
        return $this->viewer === null || $this->viewer->can($ability);
    }
}
