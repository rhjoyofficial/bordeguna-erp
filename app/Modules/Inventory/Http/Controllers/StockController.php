<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ApplyStockAdjustment;
use App\Modules\Inventory\Actions\ApplyStockTransfer;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
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
        $stockByProductWarehouse = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->with(['product:id,name,sku,low_stock_threshold', 'warehouse:id,name'])
            ->get();

        return Inertia::render('inventory/stock/index', [
            'stock' => $stockByProductWarehouse,
            'products' => Product::stocked()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can_adjust_directly' => $request->user()->can('inventory.stock.adjust'),
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

        return back();
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

        return back();
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
            'note' => ['required', 'string'],
        ]);

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            direction: AdjustmentDirection::from($data['direction']),
            quantity: $data['quantity'],
            performedBy: $request->user(),
            note: $data['note'],
            unitCost: $data['unit_cost'] ?? null,
        );

        return back();
    }

    public function storeTransfer(Request $request, ApplyStockTransfer $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'target_warehouse_id' => ['required', 'exists:warehouses,id', 'different:warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            product: Product::findOrFail($data['product_id']),
            source: Warehouse::findOrFail($data['warehouse_id']),
            target: Warehouse::findOrFail($data['target_warehouse_id']),
            quantity: $data['quantity'],
            performedBy: $request->user(),
            note: $data['note'] ?? null,
        );

        return back();
    }
}
