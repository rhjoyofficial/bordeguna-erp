<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'warehouse_id' => $request->string('warehouse_id')->toString(),
        ];

        $stockByProductWarehouse = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->when($filters['search'], fn ($query, $search) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->when($filters['warehouse_id'], fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->with(['product:id,name,sku,low_stock_threshold', 'warehouse:id,name'])
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('inventory/stock/index', [
            'stock' => $stockByProductWarehouse,
            'products' => Product::stocked()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'adjustment_reasons' => Reason::active()->forContext('inventory.stock_adjustment')->orderBy('name')->get(['id', 'name']),
            'transfer_reasons' => Reason::active()->forContext('inventory.stock_transfer')->orderBy('name')->get(['id', 'name']),
            'can_adjust_directly' => $request->user()->can('inventory.stock.adjust'),
            'in_transit_count' => StockTransfer::where('status', TransferStatus::InTransit)->count(),
            'filters' => $filters,
        ]);
    }

    /**
     * ⚠⚠ This used to call `ApplyStockAdjustment` directly, which was the live
     * second path: stock changed, movements were written, and nothing recorded
     * that a decision had been made, by whom, or when it applied. It now creates
     * an `InventoryAdjustment` like everything else, and that record either
     * approves itself under the configured threshold or waits for the Founder.
     *
     * The permission check stays: `inventory.stock.adjust` is what lets somebody
     * RAISE a correction. Whether it posts immediately is the threshold's
     * decision, not the controller's.
     */
    public function storeAdjustment(Request $request, CreateInventoryAdjustment $action): RedirectResponse
    {
        // ⚠ Either permission may RAISE a correction. `inventory.stock.request`
        // is what Staff hold, and removing their ability to raise one would have
        // been a silent loss of function in a refactor - the old flow let them
        // submit a request. Whether it POSTS is decided by the threshold and by
        // `inventory.stock.adjust`, not by who typed it.
        abort_unless(
            $request->user()->can('inventory.stock.adjust') || $request->user()->can('inventory.stock.request'),
            403,
        );

        $data = $request->validate([
            'type' => ['required', new Enum(InventoryAdjustmentType::class)],
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'direction' => ['required', new Enum(AdjustmentDirection::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'required_if:direction,increase', 'numeric', 'min:0'],
            'reason_id' => ['required', 'exists:reasons,id'],
            'effective_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        // ⚠⚠ C-INV-E1. CreateInventoryAdjustment raises NO ValidationException at
        // all: every one of its refusals is a DomainException or an
        // InvalidArgumentException, and the period lock rides in on top. The
        // whole action was therefore unreadable from this screen. `stock` is the
        // form-level key the three stock forms now render.
        try {
            $adjustment = $action->handle(
                type: InventoryAdjustmentType::from($data['type']),
                direction: AdjustmentDirection::from($data['direction']),
                product: Product::findOrFail($data['product_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                quantity: $data['quantity'],
                reason: Reason::findOrFail($data['reason_id']),
                createdBy: $request->user(),
                unitCost: $data['unit_cost'] ?? null,
                note: $data['note'] ?? null,
                effectiveDate: $data['effective_date'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()->with('success', $adjustment->approvalRequest->isComplete()
            ? 'Stock adjustment saved.'
            : 'Stock adjustment submitted for approval.');
    }

    public function storeTransfer(Request $request, DispatchStockTransfer $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'target_warehouse_id' => ['required', 'exists:warehouses,id', 'different:warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_id' => ['required', 'exists:reasons,id'],
            'note' => ['nullable', 'string'],
        ]);

        // ⚠ Transferring consumes FIFO stock, so insufficient stock is the
        // ordinary refusal here and it was a blank 500.
        try {
            $action->handle(
                product: Product::findOrFail($data['product_id']),
                source: Warehouse::findOrFail($data['warehouse_id']),
                target: Warehouse::findOrFail($data['target_warehouse_id']),
                quantity: $data['quantity'],
                reason: Reason::findOrFail($data['reason_id']),
                performedBy: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()->with('success', 'Stock transfer dispatched.');
    }

    public function transfersIndex(Request $request): Response
    {
        $filters = ['status' => $request->string('status')->toString()];

        $transfers = StockTransfer::with([
            'product:id,name,sku',
            'sourceWarehouse:id,name',
            'targetWarehouse:id,name',
            'reason:id,name',
            'dispatchedBy:id,name',
            'receivedBy:id,name',
        ])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->latest('dispatched_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('inventory/stock/transfers', [
            'transfers' => $transfers,
            'filters' => $filters,
            'can_receive' => $request->user()->can('inventory.stock.adjust'),
        ]);
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer, ReceiveStockTransfer $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        // ⚠ A bare button on the transfers screen. Receiving the same transfer
        // twice is what two people at one gate actually do.
        try {
            $action->handle($stockTransfer, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer received into stock.');
    }

    public function approveAdjustment(Request $request, InventoryAdjustment $adjustment, ApproveInventoryAdjustment $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        // ⚠⚠ Approval is what POSTS, so this one button carries the whole chain:
        // stock movements, insufficient stock, the period lock and the account
        // mappings. It is also a bare button, so it flashes.
        try {
            $action->handle($adjustment, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inventory adjustment approved.');
    }

    /**
     * ⚠ A rejected adjustment posts nothing. There is no "undo" to write,
     * because the movements were never created - approval is what posts, so
     * rejection is simply the absence of it.
     */
    public function rejectAdjustment(Request $request, InventoryAdjustment $adjustment, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        try {
            $action->handle($adjustment, $request->user(), $request->input('review_note'));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inventory adjustment rejected.');
    }
}
