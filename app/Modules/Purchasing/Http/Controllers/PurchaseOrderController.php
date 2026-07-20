<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ApprovePurchaseOrder;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrderPaymentStatus;
use App\Modules\Purchasing\Enums\PaymentStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(): Response
    {
        $orders = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name', 'approvalRequest'])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->latest()
            ->get();

        return Inertia::render('purchasing/purchase-orders/index', [
            'orders' => $orders,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'can_create' => request()->user()->can('purchasing.po.create'),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'supplier',
            'warehouse:id,name',
            'createdBy:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
            'lines.product:id,name,sku,type',
            'goodsReceipts.receivedBy:id,name',
            'goodsReceipts.lines.purchaseOrderLine:id,product_id',
            'goodsReceipts.lines.purchaseOrderLine.product:id,name,sku',
        ]);

        $user = request()->user();

        return Inertia::render('purchasing/purchase-orders/show', [
            'order' => $purchaseOrder,
            'can_approve' => $user->can('purchasing.po.approve'),
            'can_receive' => $user->can('purchasing.receipt.record'),
            'can_update_payment' => $user->can('purchasing.payment.update'),
        ]);
    }

    public function store(Request $request, CreatePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'expected_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'])->map(fn (array $line) => [
            'product' => Product::findOrFail($line['product_id']),
            'quantity' => $line['quantity'],
            'unit_cost' => $line['unit_cost'],
        ])->all();

        $order = $action->handle(
            supplier: Supplier::findOrFail($data['supplier_id']),
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            lines: $lines,
            createdBy: $request->user(),
            expectedDate: $data['expected_date'] ?? null,
            note: $data['note'] ?? null,
        );

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} created.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $action->handle($purchaseOrder, $request->user());

        return back()->with('success', 'Purchase order approved.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        $action->handle($purchaseOrder, $request->user(), $data['review_note'] ?? null);

        return back()->with('success', 'Purchase order rejected.');
    }

    public function cancel(PurchaseOrder $purchaseOrder, CancelPurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $action->handle($purchaseOrder);

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function updatePaymentStatus(Request $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrderPaymentStatus $action): RedirectResponse
    {
        $this->authorize('purchasing.payment.update');

        $data = $request->validate(['payment_status' => ['required', new Enum(PaymentStatus::class)]]);

        $action->handle($purchaseOrder, PaymentStatus::from($data['payment_status']));

        return back()->with('success', 'Payment status updated.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, RecordGoodsReceipt $action): RedirectResponse
    {
        $this->authorize('purchasing.receipt.record');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $purchaseOrder->load('lines');

        $lineReceipts = collect($data['lines'])
            ->map(fn (array $entry) => [
                'line' => $purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id']),
                'quantity' => $entry['quantity'],
            ])
            ->filter(fn (array $entry) => $entry['line'] !== null)
            ->all();

        $action->handle($purchaseOrder, $lineReceipts, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Goods receipt recorded.');
    }
}
