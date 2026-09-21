<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveStockRequest;
use App\Modules\Inventory\Actions\SubmitStockRequest;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

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

        $businessId = app(BusinessContext::class)->id();

        // ⚠ A stock request is ONLY a transfer since slice 3; corrections go to
        // `stock.adjustments.store`, which Staff may also raise. The Stock
        // screen used to send `type: adjustment` here and the refusal was never
        // rendered, so Staff could not report damage at all (21 Sep 2026).
        $data = $request->validate([
            'type' => ['required', new Enum(StockRequestType::class)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'target_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $businessId), 'different:warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_id' => ['required', Rule::exists('reasons', 'id')->where('business_id', $businessId)],
            'note' => ['nullable', 'string'],
        ], [
            'target_warehouse_id.different' => 'Stock has to move to a different warehouse.',
            'target_warehouse_id.required' => 'Choose where the stock goes.',
            'reason_id.required' => 'Choose why it is moving.',
            'product_id.required' => 'Choose the product.',
            'warehouse_id.required' => 'Choose the warehouse.',
            'quantity.required' => 'Enter how many.',
            'quantity.gt' => 'Enter a quantity above zero.',
        ]);

        // ⚠ C-INV-E1. Both of SubmitStockRequest's refusals are non-validation
        // types, so neither could be read.
        try {
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
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()
            ->with('success', 'Stock request submitted.')
            ->with('awaitingApproval', 'Request to move stock submitted. Nothing moves until a manager approves it.');
    }

    public function approve(Request $request, StockRequest $stockRequest, ApproveStockRequest $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        // ⚠⚠ Approving a stock request DISPATCHES a transfer, so this bare
        // button carries insufficient stock and the period lock with it.
        try {
            $action->handle($stockRequest, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock request approved.');
    }

    public function reject(Request $request, StockRequest $stockRequest, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $action->handle($stockRequest, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock request rejected.');
    }
}
