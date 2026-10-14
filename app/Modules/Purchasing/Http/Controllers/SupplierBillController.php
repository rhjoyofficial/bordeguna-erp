<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\RefusalMessage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The supplier's invoice: the document Accounts Payable is a ledger of.
 *
 * ⚠⚠ The engine existed and was tested for two weeks with no way to reach it.
 * No controller, no route, no screen, no sidebar entry, so goods could be
 * received but never billed, GRNI could never clear, and Accounts Payable was
 * never created from a purchase. This is the reachability rule the Sales Order
 * architecture states: an action with no route and no screen is a feature that
 * does not exist.
 *
 * ⚠ Every refusal below is caught and rendered on a key the screen actually
 * renders. `RecordSupplierBill` throws InvalidArgumentException rather than a
 * ValidationException, so unlike payments and credit notes it carries no field
 * keys of its own and the mapping has to happen here.
 */
class SupplierBillController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ['search' => $request->string('search')->toString()];

        $bills = SupplierBill::query()
            ->with(['supplier:id,name', 'allocations', 'creditAllocations'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
            ))
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'supplier' => $bill->supplier?->name,
                // ⚠ toDateString(), never the date cast. The cast serialises in
                // UTC and Dhaka is UTC+6, so midnight local becomes 18:00 the
                // previous day and a bill dated the 8th displays as the 7th.
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                // ⚠ Through Money, never the raw decimal(15,4), which renders
                // as "1000.0000" on a screen somebody has to read.
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                'outstanding' => $bill->outstanding()->toTaka(),
                'is_reversed' => $bill->isReversed(),
            ]);

        return Inertia::render('purchasing/bills/index', [
            'bills' => $bills,
            'filters' => $filters,
            'can_record' => $request->user()->can('purchasing.bill.record'),
            'billable_orders' => $this->billableOrders(),
        ]);
    }

    /**
     * The create form, always opened against ONE purchase order (D-SB-1).
     */
    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('purchasing.bill.record');

        $purchaseOrder->load(['lines.product:id,name,sku', 'supplier']);

        return Inertia::render('purchasing/bills/create', [
            // ⭐ VAT plan D7. False for every business today; the screen then
            // shows nothing about VAT at all.
            'vat' => [
                'applies' => Business::query()->find(app(BusinessContext::class)->id())?->vatAppliesOn(Carbon::today()) ?? false,
            ],
            'order' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'supplier' => [
                    'id' => $purchaseOrder->supplier->id,
                    'name' => $purchaseOrder->supplier->name,
                    // D-SB-7: shown beside the due date as guidance, never parsed.
                    'payment_terms' => $purchaseOrder->supplier->payment_terms,
                ],
                'lines' => $purchaseOrder->lines
                    ->filter(fn (PurchaseOrderLine $line) => (float) $line->billableQuantity() > 0)
                    ->map(fn (PurchaseOrderLine $line) => [
                        'id' => $line->id,
                        'product' => $line->product?->name,
                        'sku' => $line->product?->sku,
                        'quantity_ordered' => (string) $line->quantity_ordered,
                        'quantity_received' => (string) $line->quantity_received,
                        'quantity_returned' => (string) $line->quantity_returned,
                        'quantity_invoiced' => (string) $line->quantity_invoiced,
                        'billable' => $line->billableQuantity(),
                        // The agreed price, so the screen can show the variance
                        // before anybody saves.
                        'order_unit_cost' => (string) $line->unit_cost,
                    ])->values(),
            ],
        ]);
    }

    public function store(Request $request, RecordSupplierBill $action): RedirectResponse
    {
        $this->authorize('purchasing.bill.record');

        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'bill_number' => ['required', 'string', 'max:255'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            // ⭐ VAT (plan D7): said once for the whole bill, as the supplier's
            // own invoice says it once. Ignored while VAT is off.
            'prices_include_tax' => ['nullable', 'boolean'],
            'tax_recoverable' => ['nullable', 'boolean'],
        ]);

        // ⚠⚠ Resolved through the SCOPED model, never by raw id. A bare
        // `exists:` accepts another business's id, and the whole point of the
        // tenant scope is that it cannot be argued with.
        $order = PurchaseOrder::with('supplier')->findOrFail($data['purchase_order_id']);

        $lines = collect($data['lines'])
            ->filter(fn (array $line) => (float) $line['quantity'] > 0)
            ->map(fn (array $line) => [
                'line' => PurchaseOrderLine::findOrFail($line['purchase_order_line_id']),
                'quantity' => (string) $line['quantity'],
                'unitCost' => (string) $line['unit_cost'],
                'taxRecoverable' => (bool) ($data['tax_recoverable'] ?? true),
            ])
            ->values()
            ->all();

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A bill needs at least one line with a quantity greater than zero.',
            ]);
        }

        try {
            $bill = $action->handle(
                supplier: $order->supplier,
                billNumber: $data['bill_number'],
                billDate: $data['bill_date'],
                lines: $lines,
                createdBy: $request->user(),
                purchaseOrder: $order,
                dueDate: $data['due_date'] ?? null,
                note: $data['note'] ?? null,
                pricesIncludeTax: (bool) ($data['prices_include_tax'] ?? false),
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['bill_date' => $e->getMessage()]);
        } catch (RuntimeException|InvalidArgumentException|DomainException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return redirect()->route('purchasing.bills.show', $bill)
            ->with('success', "Bill {$bill->bill_number} recorded.");
    }

    public function show(SupplierBill $bill): Response
    {
        $bill->load(['supplier', 'purchaseOrder', 'lines.purchaseOrderLine.product:id,name,sku']);

        $journal = Journal::query()
            ->where('source_type', $bill->getMorphClass())
            ->where('source_id', $bill->getKey())
            ->with('lines.account')
            ->orderBy('id')
            ->get();

        return Inertia::render('purchasing/bills/show', [
            'bill' => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'note' => $bill->note,
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                // VAT inside that total; '0.00' on every untaxed bill.
                'tax_total' => Money::ofTaka((string) $bill->tax_total)->toTaka(),
                'tax_claimed' => $bill->recoverableTax()->toTaka(),
                'paid' => $bill->paidTotal()->toTaka(),
                'credited' => $bill->creditedTotal()->toTaka(),
                'outstanding' => $bill->outstanding()->toTaka(),
                'is_reversed' => $bill->isReversed(),
                'reversed_at' => $bill->reversed_at?->toDateString(),
                'reversal_note' => $bill->reversal_note,
                'supplier' => $bill->supplier?->name,
                'purchase_order_id' => $bill->purchase_order_id,
                'po_number' => $bill->purchaseOrder?->po_number,
                'lines' => $bill->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product' => $line->purchaseOrderLine?->product?->name,
                    'sku' => $line->purchaseOrderLine?->product?->sku,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => Money::ofTaka((string) $line->unit_cost)->toTaka(),
                    'order_unit_cost' => $line->purchaseOrderLine
                        ? Money::ofTaka((string) $line->purchaseOrderLine->unit_cost)->toTaka()
                        : null,
                    'line_total' => Money::ofTaka((string) $line->line_total)->toTaka(),
                ]),
            ],
            'journals' => $journal->map(fn (Journal $entry) => [
                'id' => $entry->id,
                'journal_number' => $entry->journal_number,
                'is_reversal' => $entry->reverses_journal_id !== null,
                'lines' => $entry->lines->map(fn ($line) => [
                    'account' => $line->account?->label(),
                    'amount' => $line->amount()->toTaka(),
                ]),
            ]),
            // ⚠ The next step of the purchase chain: the payment form opens with
            // this supplier chosen and this bill's outstanding typed in. Offered
            // only when there is something to pay; a reversed bill owes nothing.
            'can_pay' => request()->user()->can('purchasing.payment.record')
                && ! $bill->isReversed()
                && $bill->outstanding()->minor > 0,
            // ⚠ Offered only when it would not be refused. The refusal still
            // stands server-side either way.
            'can_reverse' => request()->user()->can('purchasing.bill.record')
                && ! $bill->isReversed()
                && $bill->paidTotal()->minor === 0
                && $bill->creditedTotal()->minor === 0
                && $bill->adjustedTotal()->minor === 0,
        ]);
    }

    public function reverse(Request $request, SupplierBill $bill, ReverseSupplierBill $action): RedirectResponse
    {
        $this->authorize('purchasing.bill.record');

        $data = $request->validate(['note' => ['nullable', 'string']]);

        try {
            $action->handle($bill, $request->user(), $data['note'] ?? null);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Bill {$bill->bill_number} reversed.");
    }

    /**
     * Purchase orders that still have goods waiting to be billed.
     *
     * ⚠ Purchasing had no equivalent of the sales side's uninvoiced query, so
     * this is the first place it exists.
     */
    private function billableOrders(): array
    {
        return PurchaseOrder::query()
            ->whereHas('lines', fn ($q) => $q->stillBillable())
            ->with('supplier:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'supplier' => $order->supplier?->name,
            ])
            ->all();
    }
}
