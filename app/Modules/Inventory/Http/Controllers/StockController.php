<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Actions\ApplyStockAdjustment;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

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

    public function storeReceipt(Request $request, RecordStockIn $action): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            quantity: $data['quantity'],
            unitCost: $data['unit_cost'],
            performedBy: $request->user(),
            batchNumber: $data['batch_number'] ?? null,
            expiryDate: $data['expiry_date'] ?? null,
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Stock receipt recorded.');
    }

    public function storeUsage(Request $request, RecordStockOut $action): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            quantity: $data['quantity'],
            performedBy: $request->user(),
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Stock usage recorded.');
    }

    public function storeAdjustment(Request $request, ApplyStockAdjustment $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'direction' => ['required', new Enum(AdjustmentDirection::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'required_if:direction,increase', 'numeric', 'min:0'],
            'reason_id' => ['required', 'exists:reasons,id'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            direction: AdjustmentDirection::from($data['direction']),
            quantity: $data['quantity'],
            reason: Reason::findOrFail($data['reason_id']),
            performedBy: $request->user(),
            note: $data['note'] ?? null,
            unitCost: $data['unit_cost'] ?? null,
        );

        return back()->with('success', 'Stock adjustment saved.');
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

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            source: Warehouse::findOrFail($data['warehouse_id']),
            target: Warehouse::findOrFail($data['target_warehouse_id']),
            quantity: $data['quantity'],
            reason: Reason::findOrFail($data['reason_id']),
            performedBy: $request->user(),
            note: $data['note'] ?? null,
        );

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

        $action->handle($stockTransfer, $request->user());

        return back()->with('success', 'Transfer received into stock.');
    }
}
