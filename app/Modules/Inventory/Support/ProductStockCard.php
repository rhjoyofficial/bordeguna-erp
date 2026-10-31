<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Models\StaffPurchaseReplacement;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\OpeningStockLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCostAllocation;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\SupplierDiscountAllocation;
use App\Modules\Sales\Models\DeliveryLineMovement;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use App\Support\Documents\DocumentLinks;
use App\Support\PerPage;
use App\Support\Traceability\BatchTrace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ⭐ THE STOCK CARD (Tally's "Stock Item Vouchers", PR5 18 Oct 2026).
 *
 * Every movement of one product in date order, with the running quantity
 * and the running value, and the document that caused it: a receipt, a
 * purchase return, a transfer, a production run (in and out), a challan, a
 * customer return, a count or adjustment, opening stock, a landed cost or a
 * discount on the goods, the owner taking stock, a staff replacement.
 *
 * ⚠ Nothing here is a second ledger. The rows ARE stock_movements; the
 * document is found through the link each module already writes
 * (goods_receipt_lines.stock_movement_id, delivery_line_movements, the
 * run's production_run_id, the movement's adjustment_id and so on), so a
 * movement nobody linked shows as its reason, never disappears.
 *
 * ⚠ Value uses StockValue::of, THE definition (value_delta wins, sign from
 * the direction), one row at a time and never summed in SQL (C-REC-6).
 * The running figures start from the very first movement, so a page in the
 * middle carries its brought-forward line.
 */
final class ProductStockCard
{
    /**
     * @return array{opening: array<string, mixed>|null, rows: LengthAwarePaginator, closing: array<string, string>}
     */
    public static function for(Product $product, ?int $warehouseId, int $page, string $path, ?User $viewer): array
    {
        $all = StockMovement::query()
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'occurred_at', ...StockValue::columns()]);

        $quantity = 0.0;
        $value = Money::zero();
        $running = [];

        foreach ($all as $movement) {
            $quantity += ($movement->direction === MovementDirection::In ? 1 : -1) * (float) $movement->quantity;
            $value = $value->plus(StockValue::of($movement));
            $running[$movement->id] = [$quantity, $value];
        }

        $perPage = PerPage::from();
        $lastPage = max(1, (int) ceil($all->count() / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = $all->forPage($page, $perPage)->values();

        $opening = null;
        if ($page > 1) {
            $before = $all->get(($page - 1) * $perPage - 1);
            [$q, $v] = $running[$before->id];
            $opening = ['quantity' => BatchTrace::qty($q), 'value' => $v->format()];
        }

        $movements = StockMovement::query()
            ->whereIn('id', $slice->pluck('id'))
            ->with(['warehouse:id,name', 'batch:id,batch_number', 'performedBy:id,name'])
            ->get()
            ->keyBy('id');

        $documents = self::documentsFor($movements->values());

        $rows = $slice->map(function (StockMovement $light) use ($movements, $documents, $running, $viewer) {
            /** @var StockMovement $m */
            $m = $movements->get($light->id);
            $doc = $documents[$m->id] ?? ($m->reverses_movement_id === null ? null : ($documents[$m->reverses_movement_id] ?? null));
            [$q, $v] = $running[$m->id];
            $signed = StockValue::of($m);
            $in = $m->direction === MovementDirection::In;

            return [
                'id' => $m->id,
                'date' => $m->occurred_at?->toDateString(),
                'reason' => $m->reason->value,
                'warehouse' => $m->warehouse?->name,
                'batch_id' => $m->batch_id,
                'batch_number' => $m->batch?->batch_number,
                'in' => $in && (float) $m->quantity > 0 ? BatchTrace::qty((float) $m->quantity) : null,
                'out' => ! $in && (float) $m->quantity > 0 ? BatchTrace::qty((float) $m->quantity) : null,
                'rate' => (float) $m->quantity > 0 && $m->unit_cost !== null ? Money::ofTaka((string) $m->unit_cost)->format() : null,
                'value' => $signed->format(),
                'balance' => BatchTrace::qty($q),
                'balance_value' => $v->format(),
                'document' => $doc === null ? null : DocumentLinks::ref($doc['model'], $viewer) + ['party' => $doc['party']],
                'undoes' => $m->reverses_movement_id !== null,
                'note' => $m->note,
                'by' => $m->performedBy?->name,
            ];
        });

        $last = $all->last();
        [$closingQty, $closingValue] = $last === null ? [0.0, Money::zero()] : $running[$last->id];

        return [
            'opening' => $opening,
            'rows' => (new LengthAwarePaginator($rows, $all->count(), $perPage, $page, ['path' => $path, 'pageName' => 'card_page']))
                ->appends(array_filter(['warehouse' => $warehouseId])),
            'closing' => ['quantity' => BatchTrace::qty($closingQty), 'value' => $closingValue->format()],
        ];
    }

    /**
     * movement id => ['model' => document, 'party' => name|null], from every
     * link table, in one query per kind.
     *
     * @param  Collection<int, StockMovement>  $movements
     * @return array<int, array{model: Model, party: string|null}>
     */
    public static function documentsFor(Collection $movements): array
    {
        // A reversal is shown against the document of the movement it undoes.
        $ids = $movements->pluck('id')->merge($movements->pluck('reverses_movement_id')->filter())->unique()->values();
        $found = [];
        $put = function (?int $movementId, ?Model $model, ?string $party = null) use (&$found) {
            if ($movementId !== null && $model !== null && ! isset($found[$movementId])) {
                $found[$movementId] = ['model' => $model, 'party' => $party];
            }
        };

        GoodsReceiptLine::query()->whereIn('stock_movement_id', $ids)
            ->with('goodsReceipt.purchaseOrder.supplier:id,name')->get()
            ->each(fn ($line) => $put($line->stock_movement_id, $line->goodsReceipt, $line->goodsReceipt?->purchaseOrder?->supplier?->name));

        PurchaseReturnLine::query()->whereIn('stock_movement_id', $ids)
            ->with('purchaseReturn.purchaseOrder.supplier:id,name')->get()
            ->each(fn ($line) => $put($line->stock_movement_id, $line->purchaseReturn, $line->purchaseReturn?->purchaseOrder?->supplier?->name));

        DeliveryLineMovement::query()->whereIn('stock_movement_id', $ids)
            ->with('deliveryLine.delivery.customer:id,name')->get()
            ->each(fn ($link) => $put($link->stock_movement_id, $link->deliveryLine?->delivery, $link->deliveryLine?->delivery?->customer?->name));

        SalesReturnLineMovement::query()->whereIn('restored_movement_id', $ids)
            ->with('returnLine.salesReturn.customer:id,name')->get()
            ->each(fn ($link) => $put($link->restored_movement_id, $link->returnLine?->salesReturn, $link->returnLine?->salesReturn?->customer?->name));

        OpeningStockLine::query()->whereIn('stock_movement_id', $ids)->with('openingStock')->get()
            ->each(fn ($line) => $put($line->stock_movement_id, $line->openingStock));

        LandedCostAllocation::query()->whereIn('stock_movement_id', $ids)->with('line.landedCost')->get()
            ->each(fn ($allocation) => $put($allocation->stock_movement_id, $allocation->line?->landedCost));

        SupplierDiscountAllocation::query()
            ->where(fn ($q) => $q->whereIn('stock_movement_id', $ids)->orWhereIn('reversal_movement_id', $ids))
            ->with('discount.supplier:id,name')->get()
            ->each(function ($allocation) use ($put) {
                $put($allocation->stock_movement_id, $allocation->discount, $allocation->discount?->supplier?->name);
                $put($allocation->reversal_movement_id, $allocation->discount, $allocation->discount?->supplier?->name);
            });

        OwnerWithdrawalLine::query()->whereIn('stock_movement_id', $ids)->with('withdrawal')->get()
            ->each(fn ($line) => $put($line->stock_movement_id, $line->withdrawal));

        StaffPurchaseReplacement::query()->whereIn('stock_movement_id', $ids)->get()
            ->each(fn ($replacement) => $put($replacement->stock_movement_id, $replacement));

        // The movement's own columns: a run, an adjustment (or the count
        // behind it), a transfer.
        $all = StockMovement::query()->whereIn('id', $ids)->get(['id', 'production_run_id', 'adjustment_id', 'transfer_group_id']);

        $runs = ProductionRun::query()->whereIn('id', $all->pluck('production_run_id')->filter()->unique())->get()->keyBy('id');
        $adjustments = InventoryAdjustment::query()->whereIn('id', $all->pluck('adjustment_id')->filter()->unique())->with('source')->get()->keyBy('id');
        $transfers = StockTransfer::query()->whereIn('transfer_group_id', $all->pluck('transfer_group_id')->filter()->unique())
            ->with(['sourceWarehouse:id,name', 'targetWarehouse:id,name'])->get()->keyBy('transfer_group_id');

        foreach ($all as $movement) {
            $put($movement->id, $runs->get($movement->production_run_id));

            $adjustment = $adjustments->get($movement->adjustment_id);
            $put($movement->id, $adjustment?->source instanceof StockCount ? $adjustment->source : $adjustment);

            $transfer = $transfers->get($movement->transfer_group_id);
            $put($movement->id, $transfer, $transfer === null ? null : $transfer->sourceWarehouse?->name.' to '.$transfer->targetWarehouse?->name);
        }

        return $found;
    }
}
