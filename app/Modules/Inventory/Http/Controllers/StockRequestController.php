<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Actions\ApproveStockRequest;
use App\Modules\Inventory\Actions\SubmitStockRequest;
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

        $filters = ['status' => $request->string('status')->toString()];

        $requests = StockRequest::with([
            'product:id,name,sku',
            'warehouse:id,name',
            'targetWarehouse:id,name',
            'reason:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
        ])
            ->when(
                $filters['status'],
                fn ($query, $status) => $query->whereHas('approvalRequest', fn ($q) => $q->where('status', $status))
            )
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('inventory/stock-requests/index', [
            'requests' => $requests,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, SubmitStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.request');

        $data = $request->validate([
            'type' => ['required', new Enum(StockRequestType::class)],
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'target_warehouse_id' => ['required', 'exists:warehouses,id', 'different:warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_id' => ['required', 'exists:reasons,id'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            type: StockRequestType::from($data['type']),
            product: Product::findOrFail($data['product_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            quantity: $data['quantity'],
            reason: Reason::findOrFail($data['reason_id']),
            requestedBy: $request->user(),
            targetWarehouse: Warehouse::findOrFail($data['target_warehouse_id']),
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Stock request submitted.');
    }

    public function approve(Request $request, StockRequest $stockRequest, ApproveStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        $action->handle($stockRequest, $request->user());

        return back()->with('success', 'Stock request approved.');
    }

    public function reject(Request $request, StockRequest $stockRequest, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        $action->handle($stockRequest, $request->user(), $data['review_note'] ?? null);

        return back()->with('success', 'Stock request rejected.');
    }
}
