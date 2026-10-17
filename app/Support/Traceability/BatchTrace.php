<?php

namespace App\Support\Traceability;

use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Sales\Models\DeliveryLineMovement;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * One batch, backward and forward (Traceability M3, 17 Oct 2026).
 *
 * Reads only what is already recorded; nobody types anything for this:
 * - every stock movement names its batch (stock_movements.batch_id);
 * - a production run's inputs are its own Out movements, its output is
 *   `output_batch_id`;
 * - a challan line is linked to the exact movements it caused
 *   (delivery_line_movements), and an invoice line to its challan line;
 * - a return names the sale movement it undoes and the movement that put the
 *   jar back on the same batch (sales_return_line_movements);
 * - a transfer makes a new batch at the destination: `source_batch_id` since
 *   17 Oct 2026, the batch number inside the transfer group before that.
 *
 * ⚠ A "batch" to a person is the batch NUMBER wherever it sits; to the ERP it
 * is one row per warehouse. So everything here works on the FAMILY: the batch
 * where it was made or received (the origin) plus every batch a transfer made
 * from it. The SQL this replaces is TRACEABILITY-REVIEW §3.1.
 *
 * ⚠ Tenant scoping comes from the models' business scope; no raw table query
 * is used on purpose.
 */
final class BatchTrace
{
    /** @var Collection<int, StockBatch> */
    private Collection $family;

    private StockBatch $origin;

    public function __construct(private readonly StockBatch $batch)
    {
        $this->origin = self::originOf($batch);
        $this->family = self::familyOf($this->origin);
    }

    public function origin(): StockBatch
    {
        return $this->origin;
    }

    /** @return Collection<int, StockBatch> */
    public function family(): Collection
    {
        return $this->family;
    }

    /** The batch a transfer took this one from, following old transfers by batch number. */
    public static function sourceOf(StockBatch $batch): ?StockBatch
    {
        if ($batch->source_batch_id !== null) {
            return StockBatch::find($batch->source_batch_id);
        }

        $in = StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where('direction', MovementDirection::In)
            ->where('reason', MovementReason::Transfer)
            ->whereNotNull('transfer_group_id')
            ->orderBy('id')
            ->first();

        if ($in === null) {
            return null;
        }

        $outLeg = StockMovement::query()
            ->where('transfer_group_id', $in->transfer_group_id)
            ->where('direction', MovementDirection::Out)
            ->whereHas('batch', fn ($q) => $batch->batch_number === null
                ? $q->whereNull('batch_number')
                : $q->where('batch_number', $batch->batch_number))
            ->orderBy('id')
            ->first();

        return $outLeg?->batch;
    }

    public static function originOf(StockBatch $batch): StockBatch
    {
        $seen = [$batch->id => true];
        $current = $batch;

        // A chain of transfers (Savar > HQ > shop) is followed to the start.
        while (($source = self::sourceOf($current)) !== null && ! isset($seen[$source->id])) {
            $seen[$source->id] = true;
            $current = $source;
        }

        return $current;
    }

    /**
     * The origin and every batch a transfer made from it, at any depth.
     *
     * @return Collection<int, StockBatch>
     */
    public static function familyOf(StockBatch $origin): Collection
    {
        $family = collect([$origin->id => $origin]);
        $queue = [$origin];

        while ($queue !== []) {
            $parent = array_shift($queue);

            $groups = StockMovement::query()
                ->where('batch_id', $parent->id)
                ->where('direction', MovementDirection::Out)
                ->where('reason', MovementReason::Transfer)
                ->whereNotNull('transfer_group_id')
                ->pluck('transfer_group_id')
                ->unique();

            if ($groups->isEmpty()) {
                continue;
            }

            $children = StockMovement::query()
                ->whereIn('transfer_group_id', $groups)
                ->where('direction', MovementDirection::In)
                ->with('batch')
                ->get()
                ->pluck('batch')
                ->filter()
                ->filter(fn (StockBatch $child) => $child->source_batch_id !== null
                    ? (int) $child->source_batch_id === $parent->id
                    : $child->batch_number === $parent->batch_number && (int) $child->product_id === (int) $parent->product_id);

            foreach ($children as $child) {
                if (! $family->has($child->id)) {
                    $family->put($child->id, $child);
                    $queue[] = $child;
                }
            }
        }

        // An Eloquent collection, so the screen can eager-load onto it.
        return new EloquentCollection($family->values()->all());
    }

    /**
     * Where this batch came from: the receipt (supplier, PO, challan) or the
     * production run (recipe, input batches and THEIR suppliers).
     *
     * @return array<string, mixed>
     */
    public function backward(): array
    {
        $source = $this->sourceOfBatch($this->origin);

        if ($source['kind'] === 'production') {
            $source['inputs'] = $this->runInputs($source['run_id']);
        }

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceOfBatch(StockBatch $batch): array
    {
        $run = ProductionRun::query()->where('output_batch_id', $batch->id)->with('billOfMaterial:id,version,name')->first();

        if ($run !== null) {
            return [
                'kind' => 'production',
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'run_status' => $run->status->value,
                'date' => $run->occurred_at?->toDateString(),
                'recipe' => $run->billOfMaterial === null ? null : 'v'.($run->bill_of_material_version ?? $run->billOfMaterial->version)
                    .($run->billOfMaterial->name ? ' ('.$run->billOfMaterial->name.')' : ''),
            ];
        }

        $in = StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where('direction', MovementDirection::In)
            ->orderBy('id')
            ->with('performedBy:id,name')
            ->first();

        $receiptLine = $in === null ? null : GoodsReceiptLine::query()
            ->where('stock_movement_id', $in->id)
            ->with(['goodsReceipt.purchaseOrder.supplier:id,name', 'goodsReceipt.receivedBy:id,name'])
            ->first();

        if ($receiptLine !== null) {
            $receipt = $receiptLine->goodsReceipt;
            $order = $receipt?->purchaseOrder;

            return [
                'kind' => 'receipt',
                'supplier' => $order?->supplier?->name,
                'purchase_order_id' => $order?->id,
                'po_number' => $order?->po_number,
                'date' => $receipt?->received_at?->toDateString(),
                'received_by' => $receipt?->receivedBy?->name,
                'supplier_challan' => $receipt?->supplier_challan_number,
            ];
        }

        return [
            'kind' => $in?->note === 'Opening stock' ? 'opening' : 'other',
            'reason' => $in?->reason?->value,
            'date' => $in?->occurred_at?->toDateString(),
            'by' => $in?->performedBy?->name,
            'note' => $in?->note,
        ];
    }

    /**
     * The batches a run actually consumed (its own Out movements, net of a
     * reversal), each with where THAT batch came from.
     *
     * @return list<array<string, mixed>>
     */
    private function runInputs(int $runId): array
    {
        return StockMovement::query()
            ->where('production_run_id', $runId)
            ->where('direction', MovementDirection::Out)
            ->whereNull('reverses_movement_id')
            ->with(['batch', 'product:id,name,sku', 'product.unitOfMeasure:id,abbreviation'])
            ->orderBy('id')
            ->get()
            ->groupBy('batch_id')
            ->map(function (Collection $movements) {
                /** @var StockMovement $first */
                $first = $movements->first();
                $batch = $first->batch;
                $origin = $batch === null ? null : self::originOf($batch);
                $from = $origin === null ? null : $this->sourceOfBatch($origin);

                return [
                    'batch_id' => $batch?->id,
                    'batch_number' => $batch?->batch_number,
                    'product' => $first->product?->name,
                    'sku' => $first->product?->sku,
                    'unit' => $first->product?->unitOfMeasure?->abbreviation,
                    'quantity' => self::qty($movements->sum(fn (StockMovement $m) => (float) $m->quantity)),
                    'made_on' => $batch?->manufactured_date?->toDateString(),
                    'expiry_date' => $batch?->expiry_date?->toDateString(),
                    'from' => $from,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Each warehouse's batch of this family: what is left, and whether it is
     * on hold.
     *
     * @return list<array<string, mixed>>
     */
    public function places(): array
    {
        $this->family->load(['warehouse:id,name', 'heldBy:id,name']);

        return $this->family->map(fn (StockBatch $batch) => [
            'id' => $batch->id,
            'warehouse' => $batch->warehouse?->name,
            'on_hand' => self::qty((float) $batch->current_quantity),
            'received' => self::qty((float) $batch->initial_quantity),
            'status' => $batch->status->value,
            'held' => $batch->isHeld(),
            'held_at' => $batch->held_at?->toDateTimeString(),
            'held_by' => $batch->heldBy?->name,
            'hold_reason' => $batch->hold_reason,
            'is_origin' => $batch->id === $this->origin->id,
        ])->values()->all();
    }

    /**
     * Production runs that consumed this batch (a raw material's forward
     * trace), with the batch each run made.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        $movements = StockMovement::query()
            ->whereIn('batch_id', $this->family->pluck('id'))
            ->where('direction', MovementDirection::Out)
            ->where('reason', MovementReason::Production)
            ->whereNotNull('production_run_id')
            ->whereNull('reverses_movement_id')
            ->get();

        if ($movements->isEmpty()) {
            return [];
        }

        $runs = ProductionRun::query()
            ->whereIn('id', $movements->pluck('production_run_id')->unique())
            ->with(['outputProduct:id,name,sku', 'outputBatch'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return $runs->map(fn (ProductionRun $run) => [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'status' => $run->status->value,
            'date' => $run->occurred_at?->toDateString(),
            'used' => self::qty($movements->where('production_run_id', $run->id)->sum(fn (StockMovement $m) => (float) $m->quantity)),
            'output_product' => $run->outputProduct?->name,
            'output_quantity' => self::qty((float) $run->output_quantity),
            'output_batch_id' => $run->output_batch_id,
            'output_batch_number' => $run->outputBatch?->batch_number,
            'output_expiry_date' => $run->outputBatch?->expiry_date?->toDateString(),
        ])->values()->all();
    }

    /**
     * Who received this batch. For a raw material, who received what the
     * runs made from it. One row per challan line, with the quantity that
     * came off THIS batch, what came back, the invoice(s) and the parcel.
     *
     * @return list<array<string, mixed>>
     */
    public function recipients(): array
    {
        $rows = $this->recipientsOf($this->family, null);

        foreach ($this->runs() as $run) {
            if ($run['status'] !== 'confirmed' || $run['output_batch_id'] === null) {
                continue;
            }

            $output = StockBatch::find($run['output_batch_id']);

            if ($output !== null) {
                $rows = array_merge($rows, $this->recipientsOf(self::familyOf(self::originOf($output)), $run));
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, StockBatch>  $family
     * @param  array<string, mixed>|null  $viaRun
     * @return list<array<string, mixed>>
     */
    private function recipientsOf(Collection $family, ?array $viaRun): array
    {
        $sales = StockMovement::query()
            ->whereIn('batch_id', $family->pluck('id'))
            ->where('direction', MovementDirection::Out)
            ->where('reason', MovementReason::Sale)
            ->with('batch:id,batch_number')
            ->get()
            ->keyBy('id');

        if ($sales->isEmpty()) {
            return [];
        }

        $links = DeliveryLineMovement::query()
            ->whereIn('stock_movement_id', $sales->keys())
            ->with([
                'deliveryLine.product:id,name,sku',
                'deliveryLine.delivery.customer:id,name,phone',
                'deliveryLine.delivery.warehouse:id,name',
                'deliveryLine.delivery.consignment.courierAccount:id,label',
                'deliveryLine.orderLine.order.address',
            ])
            ->get();

        $returned = SalesReturnLineMovement::query()
            ->whereIn('stock_movement_id', $sales->keys())
            ->get()
            ->groupBy('stock_movement_id');

        $invoices = SalesInvoiceLine::query()
            ->whereIn('delivery_line_id', $links->pluck('delivery_line_id')->unique())
            ->with('invoice:id,invoice_number,invoice_date')
            ->get()
            ->groupBy('delivery_line_id');

        return $links->groupBy('delivery_line_id')->map(function (Collection $lineLinks) use ($sales, $returned, $invoices, $viaRun) {
            $line = $lineLinks->first()->deliveryLine;
            $delivery = $line?->delivery;
            $movementIds = $lineLinks->pluck('stock_movement_id');
            $consignment = $delivery?->consignment;
            $address = $line?->orderLine?->order?->address;

            return [
                'via_run' => $viaRun === null ? null : ['id' => $viaRun['id'], 'run_number' => $viaRun['run_number']],
                'batch_number' => $sales->get($movementIds->first())?->batch?->batch_number,
                'product' => $line?->product?->name,
                'customer_id' => $delivery?->customer?->id,
                'customer' => $delivery?->customer?->name,
                'phone' => $delivery?->customer?->phone,
                'recipient' => $address?->recipient_name,
                'recipient_phone' => $address?->recipient_phone,
                'delivery_id' => $delivery?->id,
                'delivery_number' => $delivery?->delivery_number,
                'date' => $delivery?->delivered_at?->toDateString(),
                'warehouse' => $delivery?->warehouse?->name,
                'quantity' => self::qty($movementIds->sum(fn ($id) => (float) $sales->get($id)?->quantity)),
                'returned' => self::qty($movementIds->sum(fn ($id) => (float) ($returned->get($id)?->sum(fn ($r) => (float) $r->quantity) ?? 0))),
                'invoices' => ($invoices->get($line?->id) ?? collect())
                    ->map(fn (SalesInvoiceLine $invoiceLine) => $invoiceLine->invoice)
                    ->filter()
                    ->unique('id')
                    ->map(fn ($invoice) => ['id' => $invoice->id, 'number' => $invoice->invoice_number])
                    ->values()
                    ->all(),
                'consignment' => $consignment?->consignment_number,
                'courier' => $consignment?->courierAccount?->label,
                'parcel_status' => $consignment?->status?->value ?? $consignment?->status,
            ];
        })->sortBy([['date', 'asc'], ['delivery_number', 'asc']])->values()->all();
    }

    /**
     * Returns that came back onto this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function returns(): array
    {
        return SalesReturnLineMovement::query()
            ->whereIn('stock_movement_id', StockMovement::query()
                ->whereIn('batch_id', $this->family->pluck('id'))
                ->where('reason', MovementReason::Sale)
                ->select('id'))
            ->with(['returnLine.salesReturn.customer:id,name', 'returnLine.salesReturn.delivery:id,delivery_number'])
            ->orderBy('id')
            ->get()
            ->map(fn (SalesReturnLineMovement $link) => [
                'return_id' => $link->returnLine?->salesReturn?->id,
                'return_number' => $link->returnLine?->salesReturn?->return_number,
                'date' => $link->returnLine?->salesReturn?->returned_at?->toDateString(),
                'customer' => $link->returnLine?->salesReturn?->customer?->name,
                'delivery_number' => $link->returnLine?->salesReturn?->delivery?->delivery_number,
                'quantity' => self::qty((float) $link->quantity),
                'back_to_stock' => $link->restored_movement_id !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Every movement on the family, oldest first: the whole story in one list.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        return StockMovement::query()
            ->whereIn('batch_id', $this->family->pluck('id'))
            ->with(['warehouse:id,name', 'performedBy:id,name'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'date' => $m->occurred_at?->toDateString(),
                'warehouse' => $m->warehouse?->name,
                'reason' => $m->reason->value,
                'direction' => $m->direction->value,
                'quantity' => self::qty((float) $m->quantity),
                'note' => $m->note,
                'by' => $m->performedBy?->name,
                'undoes' => $m->reverses_movement_id !== null,
            ])
            ->values()
            ->all();
    }

    /** Whether any of the family is still on the book (on hand, unexpired or not). */
    public function stillHeldSomewhere(): bool
    {
        return $this->family->contains(fn (StockBatch $b) => $b->status === StockBatchStatus::Active && (float) $b->current_quantity > 0);
    }

    /** `12.5000` → `12.5`: a quantity for a screen or a file, never rounded. */
    public static function qty(float $value): string
    {
        $text = number_format($value, 4, '.', '');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
