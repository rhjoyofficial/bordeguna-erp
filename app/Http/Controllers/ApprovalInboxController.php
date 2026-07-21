<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The common approval inbox (IBOS's "Approval" screen): one page listing
 * every pending ApprovalRequest across modules. Deliberately does not
 * duplicate approve/reject logic — each row links back to the owning
 * module's existing approve/reject routes (StockRequestController,
 * PurchaseOrderController), so a future third consumer type only needs to
 * add a case to present() here, not a new approval pipeline.
 */
class ApprovalInboxController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $canApproveInventory = $user->can('inventory.stock.approve');
        $canApprovePurchasing = $user->can('purchasing.po.approve');

        abort_unless($canApproveInventory || $canApprovePurchasing, 403);

        $requests = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->when(! $canApproveInventory, fn ($query) => $query->where('approvable_type', PurchaseOrder::class))
            ->when(! $canApprovePurchasing, fn ($query) => $query->where('approvable_type', StockRequest::class))
            ->with([
                'requestedBy:id,name',
                'approvable' => fn ($morphTo) => $morphTo->morphWith([
                    StockRequest::class => ['product:id,name,sku', 'warehouse:id,name', 'targetWarehouse:id,name'],
                    PurchaseOrder::class => ['supplier:id,name', 'lines'],
                ]),
            ])
            ->latest()
            ->get()
            ->map(fn (ApprovalRequest $approvalRequest) => $this->present($approvalRequest));

        return Inertia::render('approvals/index', [
            'requests' => $requests,
            'counts' => [
                'stock_requests' => $requests->where('type', 'Stock request')->count(),
                'purchase_orders' => $requests->where('type', 'Purchase order')->count(),
            ],
        ]);
    }

    private function present(ApprovalRequest $approvalRequest): array
    {
        $approvable = $approvalRequest->approvable;

        $shared = [
            'id' => $approvalRequest->id,
            'requested_by' => $approvalRequest->requestedBy?->name,
            'requested_at' => $approvalRequest->created_at->diffForHumans(),
        ];

        if ($approvable instanceof StockRequest) {
            $direction = $approvable->type->value === 'transfer'
                ? "→ {$approvable->targetWarehouse?->name}"
                : ucfirst($approvable->direction?->value ?? '');

            return [
                ...$shared,
                'type' => 'Stock request',
                'summary' => "{$approvable->product->name} ({$approvable->product->sku}) — {$approvable->quantity} at {$approvable->warehouse->name} {$direction}",
                'approve_url' => route('inventory.stock-requests.approve', $approvable),
                'reject_url' => route('inventory.stock-requests.reject', $approvable),
            ];
        }

        if ($approvable instanceof PurchaseOrder) {
            $total = $approvable->lines->sum(fn ($line) => (float) $line->quantity_ordered * (float) $line->unit_cost);

            return [
                ...$shared,
                'type' => 'Purchase order',
                'summary' => "{$approvable->po_number} — {$approvable->supplier->name} — {$total}",
                'approve_url' => route('purchasing.purchase-orders.approve', $approvable),
                'reject_url' => route('purchasing.purchase-orders.reject', $approvable),
            ];
        }

        return [...$shared, 'type' => class_basename($approvable), 'summary' => "#{$approvable->id}", 'approve_url' => null, 'reject_url' => null];
    }
}
