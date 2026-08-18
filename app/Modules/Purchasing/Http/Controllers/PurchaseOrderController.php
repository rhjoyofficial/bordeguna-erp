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
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrderPaymentStatus;
use App\Modules\Purchasing\Enums\PaymentStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⚠⚠ C-PUR-E1. Every refusal below already carried a message written for a
 * person; none of them had anywhere to land, so each arrived as a blank 500.
 * The hierarchy is worth stating once, because it decides these catch clauses:
 * PeriodClosedException, MappingNotFound and InsufficientStockException all
 * extend RuntimeException; MissingUnitConversionException extends
 * DomainException. Catching RuntimeException, InvalidArgumentException and
 * DomainException therefore nets every business refusal these actions raise,
 * while ValidationException (which extends none of them) passes through
 * untouched because it already renders correctly.
 *
 * ⚠ Two surfaces on purpose. A form submission gets a validation error on a key
 * the form renders. A bare button has no form to attach one to, so it flashes
 * to the global error toast, the way Foundation already refuses button actions.
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $orders = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name', 'approvalRequest'])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->when($filters['search'], fn ($query, $search) => $query->where('po_number', 'like', "%{$search}%"))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('purchasing/purchase-orders/index', [
            'orders' => $orders,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'can_create' => request()->user()->can('purchasing.po.create'),
            'filters' => $filters,
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
            // ⚠⚠ The UNIT comes down with the line, and for a converted product
            // it is the PURCHASE unit. Mustard oil is ordered, received, invoiced
            // and returned in kilograms while the warehouse holds litres, and a
            // clerk typing 109 (the litres delivered) where 100 (the kilograms
            // invoiced) belongs stocks 9% too much at a 9% low cost, with every
            // number downstream balancing. The screen has to say which.
            'lines.product:id,name,sku,type,unit_of_measure_id,purchase_unit_id',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.product.purchaseUnit:id,abbreviation',
            'goodsReceipts.receivedBy:id,name',
            'goodsReceipts.lines.purchaseOrderLine:id,product_id',
            'goodsReceipts.lines.purchaseOrderLine.product:id,name,sku',
            'returns.returnedBy:id,name',
            'returns.lines.purchaseOrderLine:id,product_id',
            'returns.lines.purchaseOrderLine.product:id,name,sku',
        ]);

        $user = request()->user();
        $canApprove = $user->can('purchasing.po.approve');

        return Inertia::render('purchasing/purchase-orders/show', [
            'order' => $purchaseOrder,
            'can_approve' => $canApprove,
            'can_receive' => $user->can('purchasing.receipt.record'),
            'can_update_payment' => $user->can('purchasing.payment.update'),
            'can_return' => $canApprove,
            'can_cancel' => $canApprove
                && $purchaseOrder->status !== PurchaseOrderStatus::Cancelled
                && ($purchaseOrder->status === PurchaseOrderStatus::Open || $purchaseOrder->hasNoOutstandingReceivedStock()),
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

        try {
            $order = $action->handle(
                supplier: Supplier::findOrFail($data['supplier_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: $lines,
                createdBy: $request->user(),
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} created.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        // ⚠ A bare button. Two managers pressing approve, or one double-click,
        // is the ordinary way this refusal happens.
        try {
            $action->handle($purchaseOrder, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase order approved.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $action->handle($purchaseOrder, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase order rejected.');
    }

    public function cancel(PurchaseOrder $purchaseOrder, CancelPurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        // ⚠ "still has received stock on hand, return it before cancelling" is a
        // real business refusal, not an error. It must be readable.
        try {
            $action->handle($purchaseOrder);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function updatePaymentStatus(Request $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrderPaymentStatus $action): RedirectResponse
    {
        $this->authorize('purchasing.payment.update');

        $data = $request->validate(['payment_status' => ['required', new Enum(PaymentStatus::class)]]);

        try {
            $action->handle($purchaseOrder, PaymentStatus::from($data['payment_status']));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

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

        // ⚠⚠ THE DENSEST REFUSAL CLUSTER IN THE SYSTEM, and step 2 of the first
        // real morning. Receiving can be refused for over-receiving, for an
        // order not open, for an empty receipt, for a missing unit conversion,
        // for a closed period, and for a missing account mapping. Every one of
        // those was a blank 500. Over-receiving is the commonest of them: a
        // storekeeper types 50 where 20 remains.
        try {
            $action->handle($purchaseOrder, $lineReceipts, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Goods receipt recorded.');
    }

    public function returnGoods(Request $request, PurchaseOrder $purchaseOrder, RecordPurchaseReturn $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $purchaseOrder->load('lines');

        $lineReturns = collect($data['lines'])
            ->map(fn (array $entry) => [
                'line' => $purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id']),
                'quantity' => $entry['quantity'],
            ])
            ->filter(fn (array $entry) => $entry['line'] !== null)
            ->all();

        // ⚠ Returning reverses stock, so on top of its own refusals it inherits
        // everything ReverseStockReceipt can raise, including insufficient stock
        // when the goods have already moved on.
        try {
            $action->handle($purchaseOrder, $lineReturns, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Purchase return recorded.');
    }
}
