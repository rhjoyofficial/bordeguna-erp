<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ApproveStockRequest;
use App\Modules\Inventory\Actions\RejectStockRequest;
use App\Modules\Inventory\Actions\SubmitStockRequest;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class StockRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('inventory.stock.approve');

        return Inertia::render('inventory/stock-requests/index', [
            'requests' => StockRequest::with(['product:id,name,sku', 'warehouse:id,name', 'targetWarehouse:id,name', 'requestedBy:id,name'])
                ->orderByRaw("status = 'pending' desc")
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, SubmitStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.request');

        $data = $request->validate([
            'type' => ['required', new Enum(StockRequestType::class)],
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'target_warehouse_id' => ['required_if:type,transfer', 'nullable', 'exists:warehouses,id', 'different:warehouse_id'],
            'direction' => ['required_if:type,adjustment', 'nullable', new Enum(AdjustmentDirection::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_note' => ['required', 'string'],
        ]);

        $action->handle(
            type: StockRequestType::from($data['type']),
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            quantity: $data['quantity'],
            reasonNote: $data['reason_note'],
            requestedBy: $request->user(),
            targetWarehouse: isset($data['target_warehouse_id']) ? Warehouse::findOrFail($data['target_warehouse_id']) : null,
            direction: isset($data['direction']) ? AdjustmentDirection::from($data['direction']) : null,
        );

        return back();
    }

    public function approve(Request $request, StockRequest $stockRequest, ApproveStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        $action->handle($stockRequest, $request->user());

        return back();
    }

    public function reject(Request $request, StockRequest $stockRequest, RejectStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        $action->handle($stockRequest, $request->user(), $data['review_note'] ?? null);

        return back();
    }
}
