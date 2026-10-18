<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\ChangeSupplierBillDueDate;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Support\LikelyDuplicateBill;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\PurchaseOrderBilling;
use App\Modules\Purchasing\Support\PurchasesPulse;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\ReceiptBilling;
use App\Modules\Purchasing\Support\RefusalMessage;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Modules\Purchasing\Support\SupplierCredit;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $today = Carbon::today();
        $filters = [
            'search' => $request->string('search')->toString(),
            // Purchases redesign (15 Oct 2026): the filter chips. Each one is
            // the SAME test the money strip counts with (PurchasesPulse), so a
            // chip's count and its list agree.
            'status' => $request->string('status')->toString(),
        ];

        // ⚠ Owing bills in ONE query (PUR-008): the chips and the rows read
        // this set rather than asking outstanding() per bill.
        $owing = SupplierBill::query()->owingWithOutstanding()->get()->keyBy('id');
        $overdueIds = $owing->filter(fn (SupplierBill $bill) => ($bill->due_date ?? $bill->bill_date)?->lt($today))->keys();
        $dueWeekIds = $owing->filter(fn (SupplierBill $bill) => $bill->due_date !== null
            && $bill->due_date->gte($today)
            && $bill->due_date->lte($today->copy()->addDays(7)))->keys();

        $byStatus = fn ($query, string $status) => match ($status) {
            'open' => $query->whereIn('id', $owing->keys()),
            'overdue' => $query->whereIn('id', $overdueIds),
            'due_week' => $query->whereIn('id', $dueWeekIds),
            'paid' => $query->whereNull('reversed_at')->whereNotIn('id', $owing->keys()),
            'reversed' => $query->whereNotNull('reversed_at'),
            default => $query,
        };

        $bills = SupplierBill::query()
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number'])
            ->withSum('allocations as paid_sum', 'amount')
            ->withSum('creditAllocations as credited_sum', 'amount')
            ->withSum('adjustmentAllocations as adjusted_sum', 'amount')
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
            ))
            ->when($filters['status'], $byStatus)
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (SupplierBill $bill) use ($today, $owing) {
                $outstanding = PurchaseOrderBilling::outstandingOf($bill);
                $settledPart = Money::ofTaka((string) ($bill->paid_sum ?? '0'))
                    ->plus(Money::ofTaka((string) ($bill->credited_sum ?? '0')))
                    ->plus(Money::ofTaka((string) ($bill->adjusted_sum ?? '0')));
                $basis = $bill->due_date ?? $bill->bill_date;
                $overdueDays = $owing->has($bill->id) && $basis?->lt($today) ? (int) $basis->diffInDays($today) : 0;

                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'supplier' => $bill->supplier?->name,
                    'supplier_id' => $bill->supplier_id,
                    'purchase_order' => $bill->purchaseOrder ? ['id' => $bill->purchaseOrder->id, 'po_number' => $bill->purchaseOrder->po_number] : null,
                    // ⚠ toDateString(), never the date cast. The cast serialises in
                    // UTC and Dhaka is UTC+6, so midnight local becomes 18:00 the
                    // previous day and a bill dated the 8th displays as the 7th.
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'due_date' => $bill->due_date?->toDateString(),
                    'due_in_days' => $bill->due_date === null ? null : (int) $today->diffInDays($bill->due_date, false),
                    // ⚠ Through Money, never the raw decimal(15,4), which renders
                    // as "1000.0000" on a screen somebody has to read.
                    'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                    'outstanding' => $outstanding->toTaka(),
                    'is_reversed' => $bill->isReversed(),
                    'overdue_days' => $overdueDays,
                    // Reversed, paid, overdue, part paid, open: one word the row can colour by.
                    'state' => match (true) {
                        $bill->isReversed() => 'reversed',
                        ! $owing->has($bill->id) => 'paid',
                        $overdueDays > 0 => 'overdue',
                        $settledPart->minor > 0 => 'part_paid',
                        default => 'open',
                    },
                ];
            });

        return Inertia::render('purchasing/bills/index', [
            'bills' => $bills,
            // ⭐ Purchasing approvals (ruling P7): bills not posted yet.
            'pending_approvals' => PurchaseApproval::pendingCount(['bill']),
            'filters' => $filters,
            'counts' => [
                'all' => SupplierBill::query()->count(),
                'open' => $owing->count(),
                'overdue' => $overdueIds->count(),
                'due_week' => $dueWeekIds->count(),
                'paid' => SupplierBill::query()->whereNull('reversed_at')->whereNotIn('id', $owing->keys())->count(),
                'reversed' => SupplierBill::query()->whereNotNull('reversed_at')->count(),
            ],
            'strip' => PurchasesPulse::moneyStrip($today),
            'can_record' => $request->user()->can('purchasing.bill.record'),
            'can_pay' => $request->user()->can('purchasing.payment.record'),
            'billable_orders' => $this->billableOrders(),
            // ⭐ Ruling P5: "Bill for a supplier" (several deliveries, services).
            'bill_suppliers' => $request->user()->can('purchasing.bill.record')
                ? Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name])->all()
                : [],
        ]);
    }

    /**
     * The create form, opened from one purchase order: that order's lines as
     * before, plus (ruling P5, 19 Oct 2026) the supplier's other deliveries
     * still to bill, services and carriage, all on one bill.
     */
    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('purchasing.bill.record');

        $purchaseOrder->load(['lines.product:id,name,sku', 'supplier']);

        return Inertia::render('purchasing/bills/create', [
            ...$this->flexibleFormProps($request, $purchaseOrder->supplier, $purchaseOrder),
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
                    // The free-text note, shown beside the structured terms.
                    'payment_terms' => $purchaseOrder->supplier->payment_terms,
                ],
                // ⭐ Ruling P6: the terms this bill defaults to (the order's own,
                // else the supplier's); the form works the due date out from them.
                'terms' => PaymentTerms::forOrder($purchaseOrder, $purchaseOrder->supplier)?->toArray(),
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
                        // ⭐ 17 Oct 2026: how the order line was typed, and what
                        // the billable quantity is worth on it (its stored total
                        // or share), so the bill opens on the same figures and
                        // the variance preview compares total with total.
                        'order_entry_mode' => $line->entry_mode?->value ?? 'rate',
                        'order_line_total' => $line->lineTotal()->toTaka(),
                        'order_value_for_billable' => $line->valueOf($line->billableQuantity())->toTaka(),
                        // ⭐ PR1: the order's discount and VAT rate, so the bill opens with them.
                        'order_discount_for_billable' => $line->discountFor($line->billableQuantity())->toTaka(),
                        'order_tax_rate' => (string) $line->tax_rate,
                    ])->values(),
                // ⭐ PR1: the supplier's quotation number, the trade discount's
                // default reference when the order carried a discount.
                'supplier_reference' => $purchaseOrder->supplier_reference,
            ],
            // ── Supplier advances (ruling P2) ─────────────────────────────
            // What this supplier holds of ours, this order's own advances
            // first; the form proposes them oldest first up to the bill total.
            'advances' => $request->user()->can('purchasing.advance.record')
                ? SupplierAdvanceBook::orderedFor($purchaseOrder->supplier, $purchaseOrder->id)
                    ->map(fn (array $row) => SupplierAdvanceController::availableRow($row))->values()
                : [],
            // ── end supplier advances ─────────────────────────────────────
            // ── Payment terms and credit warning (ruling P6) ──────────────
            // A bill of goods already received adds nothing new: it moves the
            // amount from "received not billed" to "billed".
            'credit' => SupplierCredit::for($purchaseOrder->supplier),
        ]);
    }

    /**
     * ⭐ Ruling P5 (19 Oct 2026): a bill for a SUPPLIER rather than one order:
     * any of their deliveries still to bill, across orders, and service or
     * carriage lines with no order at all (an electricity or repair bill).
     */
    public function createForSupplier(Request $request): Response
    {
        $this->authorize('purchasing.bill.record');

        $supplier = Supplier::query()->findOrFail($request->integer('supplier'));

        return Inertia::render('purchasing/bills/create', [
            ...$this->flexibleFormProps($request, $supplier, null),
            'vat' => [
                'applies' => Business::query()->find(app(BusinessContext::class)->id())?->vatAppliesOn(Carbon::today()) ?? false,
            ],
            'order' => [
                'id' => null,
                'po_number' => null,
                'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'payment_terms' => $supplier->payment_terms],
                // ⭐ Ruling P6: with no order named, the supplier's own terms.
                'terms' => PaymentTerms::forSupplier($supplier)?->toArray(),
                'lines' => [],
            ],
            'advances' => $request->user()->can('purchasing.advance.record')
                ? SupplierAdvanceBook::orderedFor($supplier, null)
                    ->map(fn (array $row) => SupplierAdvanceController::availableRow($row))->values()
                : [],
            // ── Payment terms and credit warning (ruling P6) ──────────────
            'credit' => SupplierCredit::for($supplier),
        ]);
    }

    /**
     * ⭐ Ruling P5: what the flexible bill screen needs beside the order:
     * the supplier's open deliveries, the expense heads a service line may
     * use (the expense voucher's rule), the cost types carriage may take, and
     * the deliveries carriage may be added to.
     *
     * @return array<string, mixed>
     */
    private function flexibleFormProps(Request $request, Supplier $supplier, ?PurchaseOrder $order): array
    {
        $receipts = ReceiptBilling::openReceiptsOf($supplier);

        return [
            'receipts' => $receipts,
            'selected_receipt_ids' => $order === null ? [] : $receipts->where('purchase_order_id', $order->id)->pluck('id')->values(),
            'expense_accounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
                ->all(),
            'cost_types' => LandedCostType::options(),
            // Carriage may belong to ANY recent delivery: a transporter bills
            // for carrying another supplier's goods.
            'carriage_receipts' => GoodsReceipt::query()
                ->with('purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name')
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->map(fn (GoodsReceipt $receipt) => [
                    'id' => $receipt->id,
                    'label' => trim('#'.$receipt->id.' '.($receipt->purchaseOrder?->po_number ?? '').' '.($receipt->supplier_challan_number ? "challan {$receipt->supplier_challan_number}" : '').' '.($receipt->purchaseOrder?->supplier?->name ?? '').' '.($receipt->received_at?->format('d/m/Y') ?? '')),
                ])
                ->all(),
            'can_carriage' => $request->user()->can('purchasing.landed_cost.manage'),
        ];
    }

    public function store(Request $request, RecordSupplierBill $action): RedirectResponse
    {
        $this->authorize('purchasing.bill.record');

        $data = $request->validate([
            // ⭐ Ruling P5: the order the screen was opened from, if any; a
            // bill for a supplier names the supplier instead.
            'purchase_order_id' => ['nullable', 'required_without:supplier_id', 'integer', 'exists:purchase_orders,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'bill_number' => ['required', 'string', 'max:255'],
            // ⚠ Bug hunt 16 Oct 2026: payments, credit notes and expense
            // vouchers refuse a future date; a bill did not, so a typo posted
            // the GRNI-to-AP journal into a future month. A due date before the
            // bill date made it overdue the day it was entered.
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'note' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'exists:purchase_order_lines,id'],
            // ⭐ Ruling P5: the delivery (challan) line a goods line bills.
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer', 'exists:goods_receipt_lines,id'],
            // ⭐ Ruling P5: service and other non-PO lines.
            'services' => ['nullable', 'array'],
            'services.*.expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'services.*.description' => ['required', 'string', 'max:255'],
            'services.*.amount' => ['required', 'numeric', 'gt:0'],
            // ⭐ Ruling P5: carriage and other costs on the goods, through landed cost.
            'carriage' => ['nullable', 'array'],
            'carriage.*.goods_receipt_id' => ['required', 'integer', 'exists:goods_receipts,id'],
            'carriage.*.cost_type' => ['required', 'string'],
            'carriage.*.amount' => ['required', 'numeric', 'gt:0'],
            'carriage.*.allocation_method' => ['nullable', 'in:value,quantity,weight'],
            'carriage.*.description' => ['nullable', 'string', 'max:255'],
            // ⭐ Ruling P5: why a bill that looks like an earlier one is recorded anyway.
            'duplicate_reason' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            // ⭐ 17 Oct 2026 (Phase 1 part A): EITHER the unit price OR the
            // line total is typed; `entry_mode` says which when both arrive.
            // Only `unit_cost`, as before that date, is a rate entry.
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.entry_mode' => ['nullable', 'in:rate,total'],
            // ⭐ PR1 (18 Oct 2026): the VAT rate the order line carried, which
            // the bill opens with. Ignored while VAT is off.
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            // ⭐ VAT (plan D7): said once for the whole bill, as the supplier's
            // own invoice says it once. Ignored while VAT is off.
            'prices_include_tax' => ['nullable', 'boolean'],
            'tax_recoverable' => ['nullable', 'boolean'],
            // Supplier advances (ruling P2): applied to the bill as it is saved.
            'advances' => ['nullable', 'array'],
            'advances.*.key' => ['required', 'string', 'distinct', 'regex:/^(on_account|advance:\d+)$/'],
            'advances.*.amount' => ['required', 'numeric', 'gte:0'],
            // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the trade discount
            // printed on the supplier's bill, as an amount, a percentage of the
            // goods, or per line; always with the supplier's document number.
            'discount_mode' => ['nullable', 'in:amount,percent,lines'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reference' => ['nullable', 'string', 'max:120'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            // ⭐ Ruling P6: terms the bill states when they differ from the
            // order's or supplier's, and why a due date differs from the terms.
            ...PaymentTerms::rules('terms_'),
            'due_date_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'bill_date.before_or_equal' => 'A bill cannot be dated in the future. Use the date on the invoice from the supplier.',
            'due_date.after_or_equal' => 'The due date cannot be before the bill date.',
            ...PaymentTerms::messages('terms_'),
        ]);

        // ⚠⚠ Resolved through the SCOPED model, never by raw id. A bare
        // `exists:` accepts another business's id, and the whole point of the
        // tenant scope is that it cannot be argued with.
        $order = isset($data['purchase_order_id']) ? PurchaseOrder::with('supplier')->findOrFail($data['purchase_order_id']) : null;
        $supplier = $order?->supplier ?? Supplier::query()->findOrFail($data['supplier_id']);
        $data['lines'] ??= [];

        // ⚠ A refused figure lands under its own box (PurchaseLineEntry); a
        // line left at 0 is "not on this bill" and is not checked.
        $problems = [];

        foreach ($data['lines'] as $index => $line) {
            if ((float) $line['quantity'] <= 0) {
                continue;
            }

            foreach (PurchaseLineEntry::problems((string) $line['quantity'], $line['unit_cost'] ?? null, $line['line_total'] ?? null, $line['entry_mode'] ?? null) as $field => $problem) {
                $problems["lines.{$index}.{$field}"] = $problem;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $lines = collect($data['lines'])
            ->filter(fn (array $line) => (float) $line['quantity'] > 0)
            ->map(fn (array $line) => [
                'line' => PurchaseOrderLine::findOrFail($line['purchase_order_line_id']),
                'receiptLine' => isset($line['goods_receipt_line_id']) ? GoodsReceiptLine::query()->findOrFail($line['goods_receipt_line_id']) : null,
                'quantity' => (string) $line['quantity'],
                'unitCost' => isset($line['unit_cost']) ? (string) $line['unit_cost'] : null,
                'lineTotal' => isset($line['line_total']) ? (string) $line['line_total'] : null,
                'entryMode' => $line['entry_mode'] ?? null,
                'taxRecoverable' => (bool) ($data['tax_recoverable'] ?? true),
                // ⭐ PR1: the rate the order carried; blank = the product's class.
                ...(isset($line['tax_rate']) && $line['tax_rate'] !== '' ? ['taxRate' => (string) $line['tax_rate']] : []),
            ])
            ->values()
            ->all();

        // ── Ruling P5: services and carriage ──────────────────────────────
        $services = collect($data['services'] ?? [])
            ->map(fn (array $row) => [
                'account' => Account::query()->findOrFail($row['expense_account_id']),
                'description' => $row['description'],
                'amount' => (string) $row['amount'],
            ])->values()->all();

        $carriage = collect($data['carriage'] ?? [])
            ->map(function (array $row) {
                GoodsReceipt::query()->findOrFail($row['goods_receipt_id']);

                return [
                    'goods_receipt_id' => (int) $row['goods_receipt_id'],
                    'cost_type' => $row['cost_type'],
                    'amount' => (string) $row['amount'],
                    'allocation_method' => $row['allocation_method'] ?? 'value',
                    'description' => $row['description'] ?? null,
                ];
            })->values()->all();

        if ($lines === [] && $services === [] && $carriage === []) {
            throw ValidationException::withMessages([
                'lines' => 'A bill needs at least one line with a quantity greater than zero, a service, or carriage.',
            ]);
        }

        // ⚠ D-SB-1 still holds when the form names ONE order: every line must
        // be that order's (RecordSupplierBill refuses the rest). A bill across
        // orders is posted for the SUPPLIER with no order named; when all its
        // goods lines turn out to be one order's, the bill names that order.
        $orderIds = collect($lines)->map(fn (array $line) => (int) $line['line']->purchase_order_id)->unique()->values();
        $billOrder = $order ?? ($orderIds->count() === 1 ? PurchaseOrder::query()->findOrFail($orderIds->first()) : null);
        // ── end services and carriage ─────────────────────────────────────

        // ── Supplier advances (ruling P2) ─────────────────────────────────
        $advanceRows = array_values(array_filter($data['advances'] ?? [], fn (array $row) => (float) $row['amount'] > 0));

        if ($advanceRows !== [] && ! $request->user()->can('purchasing.advance.record')) {
            throw ValidationException::withMessages(['advances' => 'You may record the bill, but applying advances needs the permission to pay suppliers.']);
        }
        // ── end supplier advances ─────────────────────────────────────────

        // ⭐ Supplier discounts (ruling P3): the trade discount on the supplier's bill.
        $tradeDiscount = $this->tradeDiscountFrom($data);

        // ⭐ Ruling P6: an override of the terms' due date needs its reason,
        // asked under the reason box before anything is written.
        $billTerms = PaymentTerms::of($data['terms_type'] ?? null, $data['terms_days'] ?? null);

        try {
            PaymentTerms::snapshot($supplier, $billOrder, Carbon::parse($data['bill_date']), $data['due_date'] ?? null, $billTerms, $data['due_date_reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['due_date_reason' => $e->getMessage()]);
        }

        try {
            // ⚠ One transaction: a refused advance (or discount) leaves no bill behind.
            // The trade discount is taken first, so the advance applies to what is really owed.
            $bill = DB::transaction(function () use ($action, $supplier, $billOrder, $data, $lines, $request, $advanceRows, $tradeDiscount, $services, $carriage, $billTerms) {
                $bill = $action->handle(
                    supplier: $supplier,
                    billNumber: $data['bill_number'],
                    billDate: $data['bill_date'],
                    lines: $lines,
                    createdBy: $request->user(),
                    purchaseOrder: $billOrder,
                    dueDate: $data['due_date'] ?? null,
                    note: $data['note'] ?? null,
                    pricesIncludeTax: (bool) ($data['prices_include_tax'] ?? false),
                    tradeDiscount: $tradeDiscount,
                    terms: $billTerms,
                    dueDateReason: $data['due_date_reason'] ?? null,
                    expenseLines: $services,
                    carriageLines: $carriage,
                    duplicateReason: $data['duplicate_reason'] ?? null,
                    warnOnLikelyDuplicates: true,
                );

                // Supplier advances (ruling P2): dated the bill date.
                if ($advanceRows !== []) {
                    app(ApplySupplierAdvances::class)->handle($bill, SupplierAdvanceController::resolveRows($advanceRows), $data['bill_date'], $request->user());
                }

                return $bill;
            });
        } catch (PurchaseApprovalRequired $e) {
            // ⭐ Purchasing approvals (ruling P7): nothing posted; saved as
            // pending with the advances the maker chose, applied on approval.
            $e->draft->withFollowUp('advances', $advanceRows);
            $approval = app(PurchasingApprovalGate::class)->hold($e, $request->user());

            return redirect()->route('purchasing.approvals.show', $approval)
                ->with('success', "Bill {$data['bill_number']} needs a second person's approval, so it is saved as {$approval->number()} and posts when they approve.");
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['bill_date' => $e->getMessage()]);
        } catch (LikelyDuplicateBill $e) {
            // ⭐ Ruling P5: the screen asks why, and sends the bill again with it.
            throw ValidationException::withMessages(['duplicate' => $e->getMessage()]);
        } catch (RuntimeException|InvalidArgumentException|DomainException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return redirect()->route('purchasing.bills.show', $bill)
            ->with('success', "Bill {$bill->bill_number} recorded.".$this->carriageSentence($bill));
    }

    /** ⭐ Ruling P5: where the bill's carriage went in the landed-cost workflow. */
    private function carriageSentence(SupplierBill $bill): string
    {
        $parts = $bill->carriage()->with('approvalRequest')->get()->map(fn (LandedCost $landedCost) => match (true) {
            $landedCost->status === LandedCostStatus::Posted => "carriage {$landedCost->number} posted",
            $landedCost->status === LandedCostStatus::Approved => "carriage {$landedCost->number} approved, waiting to be posted",
            $landedCost->isAwaitingApproval() => "carriage {$landedCost->number} sent for approval",
            default => "carriage {$landedCost->number} saved as a draft on Landed costs",
        });

        return $parts->isEmpty() ? '' : ' '.ucfirst($parts->implode('; ')).'.';
    }

    /**
     * ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the typed trade discount,
     * or null when none was typed. Per-line amounts are keyed by the order line.
     *
     * @param  array<string, mixed>  $data
     * @return array{mode: string, value: ?string, reference: string, reason: ?string, lines: array<int, string>}|null
     */
    private function tradeDiscountFrom(array $data): ?array
    {
        $mode = $data['discount_mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        $lines = collect($data['lines'])
            ->filter(fn (array $line) => (float) ($line['discount'] ?? 0) > 0 && (float) $line['quantity'] > 0)
            // ⭐ Ruling P5: one order line may arrive on several deliveries of
            // one bill; its discounts are added up, never overwritten.
            ->groupBy(fn (array $line) => (int) $line['purchase_order_line_id'])
            ->map(fn ($rows) => Money::sum($rows->map(fn (array $line) => Money::ofTaka((string) $line['discount'])))->toTaka())
            ->all();

        $typed = $mode === 'lines' ? $lines !== [] : (float) ($data['discount_value'] ?? 0) > 0;

        if (! $typed) {
            return null;
        }

        if (trim((string) ($data['discount_reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['discount_reference' => "Give the supplier's document number the discount is printed on."]);
        }

        return [
            'mode' => $mode,
            'value' => isset($data['discount_value']) ? (string) $data['discount_value'] : null,
            'reference' => (string) $data['discount_reference'],
            'reason' => $data['discount_reason'] ?? null,
            'lines' => $lines,
        ];
    }

    public function show(SupplierBill $bill, SupplierDiscountActionPolicy $discountPolicy): Response
    {
        $bill->load(['supplier', 'purchaseOrder', 'landedCost:id,number,goods_receipt_id,journal_id', 'lines.purchaseOrderLine.product:id,name,sku',
            'lines.purchaseOrderLine.purchaseOrder:id,po_number', 'lines.goodsReceiptLine.goodsReceipt:id,supplier_challan_number', 'lines.expenseAccount']);

        // ⭐ 18 Oct 2026: a landed cost's bill has no journal of its own; the
        // landed cost's journal is the one that credited Accounts Payable.
        $source = $bill->landedCost ?? $bill;

        $journal = Journal::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->with('lines.account')
            ->orderBy('id')
            ->get();

        return Inertia::render('purchasing/bills/show', [
            // ⭐ Purchasing approvals (ruling P7): who approved it, and why.
            'approval' => PurchaseApproval::postedVia($bill),
            'bill' => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                // ⭐ Ruling P6: the terms the bill KEPT when it was recorded, and
                // where its due date came from.
                'terms' => PaymentTerms::forBill($bill)?->toArray(),
                'due_date_source' => $bill->due_date_source,
                'due_date_reason' => $bill->due_date_reason,
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
                'supplier_id' => $bill->supplier_id,
                'purchase_order_id' => $bill->purchase_order_id,
                'po_number' => $bill->purchaseOrder?->po_number,
                // ⭐ Ruling P5 (19 Oct 2026): the orders a bill across several covers,
                // the non-PO flag, the duplicate override and the carriage it raised.
                'orders' => $bill->lines->map(fn ($line) => $line->purchaseOrderLine?->purchaseOrder)->filter()->unique('id')
                    ->map(fn (PurchaseOrder $o) => ['id' => $o->id, 'po_number' => $o->po_number])->values(),
                'has_non_po_lines' => $bill->hasNonPoLines(),
                'duplicate_override_reason' => $bill->duplicate_override_reason,
                'duplicate_matches' => $bill->duplicate_matches ?? [],
                'carriage' => $bill->carriage()->with(['approvalRequest', 'bills:id,landed_cost_id,bill_number'])->orderBy('id')->get()
                    ->map(fn (LandedCost $landedCost) => [
                        'id' => $landedCost->id,
                        'number' => $landedCost->number,
                        'total' => Money::ofMinor((int) $landedCost->total_paisa)->toTaka(),
                        'status' => $landedCost->isAwaitingApproval() ? 'awaiting' : $landedCost->status->value,
                        'goods_receipt_id' => $landedCost->goods_receipt_id,
                        'url' => route('purchasing.landed-costs.index', ['receipt' => $landedCost->goods_receipt_id]),
                        'bills' => $landedCost->bills->map(fn ($b) => ['id' => $b->id, 'bill_number' => $b->bill_number])->values(),
                    ])->values(),
                'landed_cost' => $bill->landedCost === null ? null : [
                    'number' => $bill->landedCost->number,
                    'url' => route('purchasing.landed-costs.index', ['receipt' => $bill->landedCost->goods_receipt_id]),
                ],
                'lines' => $bill->lines->map(fn ($line) => [
                    'id' => $line->id,
                    // ⭐ Ruling P5: goods (with the order and challan) or a service line.
                    'kind' => $line->line_kind ?? 'goods',
                    'description' => $line->description,
                    'expense_account' => $line->expenseAccount?->label(),
                    'po_number' => $line->purchaseOrderLine?->purchaseOrder?->po_number,
                    'challan' => $line->goodsReceiptLine?->goodsReceipt?->supplier_challan_number,
                    'goods_receipt_id' => $line->goodsReceiptLine?->goods_receipt_id,
                    'product' => $line->purchaseOrderLine?->product?->name,
                    'sku' => $line->purchaseOrderLine?->product?->sku,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => Money::ofTaka((string) $line->unit_cost)->toTaka(),
                    'order_unit_cost' => $line->purchaseOrderLine
                        ? Money::ofTaka((string) $line->purchaseOrderLine->unit_cost)->toTaka()
                        : null,
                    'line_total' => Money::ofTaka((string) $line->line_total)->toTaka(),
                    // 17 Oct 2026: which figure was typed on this line.
                    'entry_mode' => $line->entry_mode?->value ?? 'rate',
                ]),
                // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): each one with
                // where its cost went and the Reverse action of the corrections
                // process. Live ones reduce `outstanding` already.
                'discounted' => Money::ofTaka((string) ($bill->discounts()->whereNull('reversed_at')->sum('amount') ?: '0'))->toTaka(),
                'discounts' => $bill->discounts()->with(['allocations.batch:id,batch_number', 'journal:id,journal_number', 'reversalJournal:id,journal_number', 'reversedBy:id,name'])->orderBy('id')->get()
                    ->map(fn (SupplierDiscount $discount) => [
                        'id' => $discount->id,
                        'number' => $discount->number,
                        'kind' => $discount->kind,
                        'kind_label' => $discount->label(),
                        'date' => $discount->discount_on?->toDateString(),
                        'amount' => $discount->money()->toTaka(),
                        'percent' => $discount->percent === null ? null : rtrim(rtrim((string) $discount->percent, '0'), '.'),
                        'reference' => $discount->reference,
                        'reason' => $discount->reason,
                        'payment_id' => $discount->supplier_payment_id,
                        'on_hand' => Money::ofMinor((int) $discount->on_hand_paisa)->toTaka(),
                        'consumed' => Money::ofMinor((int) $discount->consumed_paisa)->toTaka(),
                        'batches' => $discount->allocations->map(fn ($a) => [
                            'batch' => $a->batch?->batch_number,
                            'on_hand' => Money::ofMinor((int) $a->on_hand_share_paisa)->toTaka(),
                            'consumed' => Money::ofMinor((int) $a->consumed_share_paisa)->toTaka(),
                            'old_unit_cost' => (string) $a->old_unit_cost,
                            'new_unit_cost' => $a->new_unit_cost === null ? null : (string) $a->new_unit_cost,
                        ])->values(),
                        'journal' => $discount->journal?->journal_number,
                        'reversal' => $discount->reversalSummary(),
                        'actions' => $discountPolicy->actionsFor($discount, request()->user())->toArray()['actions'],
                    ])->values(),
            ],
            'reversalReasons' => Reason::query()->active()->forContext('reversal')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->all(),
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
            // ── Supplier advances (ruling P2) ─────────────────────────────
            'advances' => $this->advancesFor($bill),
            // ── end supplier advances ─────────────────────────────────────
            // ⚠ Offered only when it would not be refused. The refusal still
            // stands server-side either way.
            // ⭐ Ruling P6: a supplier agreed a later date; changed with a reason.
            'can_change_due_date' => request()->user()->can('purchasing.bill.record')
                && ! $bill->isReversed()
                && $bill->outstanding()->minor > 0,
            'can_reverse' => request()->user()->can('purchasing.bill.record')
                && ! $bill->isReversed()
                && ! $bill->isLandedCostBill()
                && $bill->paidTotal()->minor === 0
                && $bill->creditedTotal()->minor === 0
                && $bill->adjustedTotal()->minor === 0
                && $bill->carriage()->get()->every(fn (LandedCost $landedCost) => $landedCost->isEditable()),
        ]);
    }

    /**
     * ⭐ Ruling P6: change a recorded bill's due date, with a reason (popup on
     * the bill page). The date is not a figure in the books, so no journal
     * moves; the change is in the bill's history.
     */
    public function changeDueDate(Request $request, SupplierBill $bill, ChangeSupplierBillDueDate $action): RedirectResponse
    {
        $this->authorize('purchasing.bill.record');

        $data = $request->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say why the due date is changing.',
        ]);

        try {
            $action->handle($bill, $data['due_date'], $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['due_date' => $e->getMessage()]);
        }

        return back()->with('success', "Bill {$bill->bill_number} is now due on ".Carbon::parse($data['due_date'])->format('d/m/Y').'.');
    }

    /**
     * ⭐ Ruling P6: supplier bills by due date. Overdue (worst first), due
     * today, then due within the window: one row per bill that still owes,
     * with its terms, how late or how soon, and what is left to pay.
     *
     * ⚠ A bill with no due date is counted from its bill date, the rule the
     * AP ageing, the bill list and the money strip already use, and the row
     * says so.
     */
    public function due(Request $request): Response
    {
        $today = Carbon::today();
        $window = in_array($request->integer('days', 7), [7, 14, 30], true) ? $request->integer('days', 7) : 7;
        $until = $today->copy()->addDays($window)->toDateString();

        $rows = SupplierBill::query()->owingWithOutstanding()
            ->with('supplier:id,name')
            ->get()
            ->map(function (SupplierBill $bill) use ($today) {
                // ⚠ Date strings, never the cast (Dhaka is UTC+6).
                $basis = Carbon::parse(($bill->due_date ?? $bill->bill_date)->toDateString());
                $days = (int) $today->diffInDays($basis, false);

                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'supplier' => $bill->supplier?->name,
                    'supplier_id' => $bill->supplier_id,
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'due_date' => $basis->toDateString(),
                    'no_due_date' => $bill->due_date === null,
                    'terms' => PaymentTerms::forBill($bill)?->toArray(),
                    'days' => $days,
                    'outstanding' => Money::ofMinor((int) $bill->outstanding_paisa)->toTaka(),
                    'outstanding_minor' => (int) $bill->outstanding_paisa,
                    'group' => match (true) {
                        $days < 0 => 'overdue',
                        $days === 0 => 'today',
                        default => 'soon',
                    },
                ];
            })
            ->filter(fn (array $row) => $row['due_date'] <= $until)
            ->sortBy([['days', 'asc'], ['bill_number', 'asc']])
            ->values();

        $group = fn (string $name) => $rows->where('group', $name);

        return Inertia::render('purchasing/bills/due', [
            'rows' => $rows->map(fn (array $row) => collect($row)->except('outstanding_minor')->all())->all(),
            'days' => $window,
            'groups' => collect(['overdue', 'today', 'soon'])->mapWithKeys(fn (string $name) => [$name => [
                'count' => $group($name)->count(),
                'total' => Money::ofMinor((int) $group($name)->sum('outstanding_minor'))->toTaka(),
            ]])->all(),
            'can_pay' => $request->user()->can('purchasing.payment.record'),
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
     * ⭐ Supplier advances (ruling P2): what was applied to this bill, and,
     * while it still owes and the user may apply, what is available with the
     * default proposal (this order's advances first, then oldest first).
     *
     * @return array<string, mixed>
     */
    private function advancesFor(SupplierBill $bill): array
    {
        $canApply = request()->user()->can('purchasing.advance.record') && ! $bill->isReversed() && $bill->outstanding()->minor > 0;
        $applications = SupplierAdvanceApplication::query()->where('supplier_bill_id', $bill->id)
            ->with('advance:id,number')->orderBy('applied_on')->orderBy('id')->get();

        return [
            'applied' => Money::sum($applications->filter(fn ($a) => ! $a->isReversed())->map(fn ($a) => $a->money()))->toTaka(),
            'applications' => $applications->map(fn (SupplierAdvanceApplication $a) => [
                'id' => $a->id,
                'number' => $a->number,
                'applied_on' => $a->applied_on?->toDateString(),
                'amount' => $a->money()->toTaka(),
                'tds_covered' => Money::ofTaka((string) $a->tds_covered)->toTaka(),
                'advance' => $a->advance ? ['id' => $a->advance->id, 'number' => $a->advance->number] : null,
                'source_note' => $a->source_note,
                'is_reversed' => $a->isReversed(),
                'can_reverse' => ! $a->isReversed() && request()->user()->can('purchasing.advance.record'),
            ])->values(),
            'available' => $canApply ? SupplierAdvanceBook::ordered($bill)->map(fn (array $row) => SupplierAdvanceController::availableRow($row))->values() : [],
            'proposal' => $canApply ? array_map(fn (array $p) => ['key' => $p['key'], 'amount' => $p['amount']->toTaka()], SupplierAdvanceBook::proposalFor($bill)) : [],
            'can_apply' => $canApply,
            'today' => Carbon::today()->toDateString(),
        ];
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
