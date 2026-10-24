<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApplySalesReturnCredit;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Support\CostVisibility;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use App\Modules\Sales\Support\ReturnFormFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods coming back - the screen over RecordSalesReturn.
 *
 * ⚠ The reachability rule (SALES-ORDER-ARCHITECTURE.md F1): an engine action
 * with no route and no screen is a feature that does not exist. This
 * controller, its routes and the sidebar link ship in the same commit as the
 * engine.
 */
class SalesReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
        ];

        $returns = SalesReturn::query()
            ->with(['customer:id,name', 'delivery:id,delivery_number'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('return_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->orderByDesc('returned_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SalesReturn $return) => array_merge($return->toArray(), [
                // ⚠ The Dhaka date: the date cast serialises as a UTC timestamp,
                // and the list sliced it, showing every return a day early.
                'returned_at' => $return->returned_at?->toDateString(),
                'refunded_total' => $return->refundedTotal()->toTaka(),
            ]));

        return Inertia::render('sales/returns/index', [
            'returns' => $returns,
            'filters' => $filters,
            'can_record' => $request->user()->can('sales.returns.record'),
        ]);
    }

    /**
     * Pick the challan that came back, see what each line can still return.
     *
     * ⚠ The server states every cap (returnable, still-billable) so the screen
     * previews exactly what the engine will enforce - never a second opinion.
     */
    public function create(Request $request): Response
    {
        $deliveryId = $request->integer('delivery_id') ?: null;
        $delivery = null;
        $lines = [];
        $invoiceLookupError = null;

        // ⭐ B20 (Joy, 24 Oct 2026): "You open the original Sales Invoice and
        // click Create Return". The invoice names the challan(s) it billed;
        // the form opens on the first (or the one asked for) and shows only
        // the lines this invoice billed, at the price it billed them.
        $invoice = null;
        $invoiceId = $request->integer('invoice_id') ?: null;

        if ($invoiceId !== null) {
            $invoice = SalesInvoice::query()->with('lines.deliveryLine.delivery:id,delivery_number,delivered_at')->find($invoiceId);

            if ($invoice === null) {
                $invoiceLookupError = 'That invoice cannot be found.';
            } elseif ($invoice->is_staff_sale) {
                $invoiceLookupError = "Invoice {$invoice->invoice_number} is a staff purchase. A defect is replaced from HRM > Staff purchases, never returned for credit.";
                $invoice = null;
            } elseif ($invoice->isReversed()) {
                $invoiceLookupError = "Invoice {$invoice->invoice_number} has been credited in full, so nothing on it can be returned.";
                $invoice = null;
            } else {
                $ids = $invoice->lines->pluck('deliveryLine.delivery_id')->filter()->unique()->values();

                if ($deliveryId === null || ! $ids->contains($deliveryId)) {
                    $deliveryId = $ids->first();
                }

                if ($deliveryId === null) {
                    $invoiceLookupError = "Invoice {$invoice->invoice_number} has no challan to return against.";
                }
            }
        }

        // ⚠ The counter entry: a customer at the till holds a RECEIPT with an
        // invoice number, not a challan number. Resolve the delivery through
        // the invoice so the cashier types the number they were handed. Same
        // screen, same caps, same engine - only the way in changes.
        $invoiceNumber = trim((string) $request->query('invoice_number', ''));
        if ($deliveryId === null && $invoiceNumber !== '') {
            $invoice = SalesInvoice::query()
                ->with('lines.deliveryLine:id,delivery_id')
                ->where('invoice_number', $invoiceNumber)
                ->first();

            $deliveryId = $invoice?->lines
                ->pluck('deliveryLine.delivery_id')
                ->filter()
                ->unique()
                ->first();

            if ($deliveryId === null) {
                $invoiceLookupError = $invoice === null
                    ? "No invoice carries the number \"{$invoiceNumber}\". Check the receipt - the number starts with INV."
                    : "Invoice {$invoiceNumber} has no delivery to return against.";
            }
        }

        if ($deliveryId !== null) {
            $delivery = Delivery::query()
                ->with(['customer:id,name', 'warehouse:id,name', 'lines.product:id,name,sku'])
                ->findOrFail($deliveryId);

            // ⭐ B20: from an invoice, only the lines it billed.
            $billed = $invoice?->lines->pluck('delivery_line_id')->filter()->all();

            $lines = $delivery->lines
                ->when($billed !== null, fn ($all) => $all->whereIn('id', $billed))
                ->map(fn ($line) => [
                    'delivery_line_id' => $line->id,
                    'product_name' => $line->product->name,
                    'product_sku' => $line->product->sku,
                    'quantity_delivered' => (string) $line->quantity,
                    'quantity_returnable' => $line->quantityReturnable(),
                    // How much of a return would relieve GDNI vs credit AR - shown
                    // so the split is never a surprise, decided by the engine.
                    'quantity_uninvoiced' => $line->quantityUninvoiced(),
                ] + ReturnFormFacts::forLine($line, $invoice))->values()->all();
        }

        // Challans with anything left to return, newest first, for the picker.
        $deliveries = Delivery::query()
            ->with('customer:id,name')
            ->whereHas('lines', fn ($q) => $q->whereColumn('quantity_returned', '<', 'quantity'))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'delivery_number', 'delivered_at', 'customer_id'])
            // ⚠ Bug hunt 16 Oct 2026: a raw datetime reaches the page as UTC, so
            // a challan stored before 06:00 Dhaka showed the day before.
            ->map(fn (Delivery $d) => [
                'id' => $d->id,
                'delivery_number' => $d->delivery_number,
                'delivered_at' => $d->delivered_at?->toDateString(),
                'customer' => $d->customer?->only(['id', 'name']),
            ]);

        return Inertia::render('sales/returns/create', [
            // ⭐ B20: the invoice the return is opened from, its challans, and
            // where good goods may go (the challan's warehouse first).
            'invoice' => $invoice === null ? null : ReturnFormFacts::invoice($invoice),
            'deliveryWarehouseId' => $delivery?->warehouse_id,
            'sellableWarehouses' => Warehouse::query()->where('is_active', true)->saleable()->orderBy('name')->get(['id', 'name']),
            'can_exchange' => $request->user()->can('sales.orders.record'),
            'deliveries' => $deliveries,
            'deliveryId' => $deliveryId,
            'delivery' => $delivery?->only(['id', 'delivery_number', 'customer', 'warehouse']),
            'lines' => $lines,
            'invoiceLookupError' => $invoiceLookupError,
            // ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): where damaged
            // goods may wait instead of being written off at once.
            'quarantineWarehouses' => Warehouse::query()->where('is_active', true)->where('not_for_sale', true)->orderBy('name')->get(['id', 'name']),
            'can_refund' => $request->user()->can('sales.returns.refund'),
            // ⚠ Only accounts a refund can be booked out of (see withLedger).
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(
        Request $request,
        RecordSalesReturn $action,
        RecordCustomerRefund $refundAction,
    ): RedirectResponse {
        $data = $request->validate([
            'delivery_id' => ['required', 'integer', 'exists:deliveries,id'],
            // ⚠ Bug hunt 16 Oct 2026: a future date was a 500 page.
            'returned_at' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['required', 'integer', 'exists:delivery_lines,id'],
            'lines.*.quantity_to_stock' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_written_off' => ['nullable', 'numeric', 'min:0'],
            // ⭐ B7: damaged, kept in the Not for sale warehouse.
            'lines.*.quantity_to_quarantine' => ['nullable', 'numeric', 'min:0'],
            'quarantine_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            // ⭐ B20: where good goods go, the invoice it was opened from, and
            // the exchange tick (a new order for the replacement after it).
            'stock_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'sales_invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'exchange' => ['nullable', 'boolean'],
            // The counter case (C-SR-6): cash back in hand, same transaction.
            'refund' => ['nullable', 'array'],
            'refund.money_account_id' => ['required_with:refund', 'integer', 'exists:money_accounts,id'],
            'refund.amount' => ['required_with:refund', 'numeric', 'gt:0'],
            // ⭐ B20: a refund split over money accounts. A row with no amount
            // takes all that is left after the invoice was reduced.
            'refunds' => ['nullable', 'array', 'max:5'],
            'refunds.*.money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'refunds.*.amount' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'returned_at.before_or_equal' => 'A return cannot be dated in the future.',
        ]);

        // ⚠ Recording is Staff-level; CASH going back is not (C-SR-9).
        if ((isset($data['refund']) || ! empty($data['refunds'])) && ! $request->user()->can('sales.returns.refund')) {
            abort(403, 'Cash refunds need Owner or Manager.');
        }

        $delivery = Delivery::with('lines')->findOrFail($data['delivery_id']);
        $deliveryLines = $delivery->lines->keyBy('id');

        $lines = collect($data['lines'])->map(function (array $line) use ($deliveryLines) {
            return [
                'deliveryLine' => $deliveryLines[$line['delivery_line_id']]
                    ?? abort(422, 'That line belongs to a different delivery.'),
                'quantityToStock' => (string) ($line['quantity_to_stock'] ?? '0'),
                'quantityWrittenOff' => (string) ($line['quantity_written_off'] ?? '0'),
                'quantityToQuarantine' => (string) ($line['quantity_to_quarantine'] ?? '0'),
            ];
        })->all();

        // ⚠ Return and instant refund are ONE transaction: nobody hands cash
        // over for a return that then fails to record.
        // ⚠ C-SLS-E1. A return dated into a closed month is refused; the whole
        // transaction rolls back, so nothing partial survives the message.
        try {
            $return = DB::transaction(function () use ($action, $refundAction, $delivery, $lines, $data, $request) {
                $return = $action->handle(
                    delivery: $delivery,
                    lines: $lines,
                    createdBy: $request->user(),
                    returnedAt: $data['returned_at'] ?? null,
                    note: $data['note'] ?? null,
                    quarantine: isset($data['quarantine_warehouse_id']) ? Warehouse::findOrFail($data['quarantine_warehouse_id']) : null,
                    goodWarehouse: isset($data['stock_warehouse_id']) ? Warehouse::findOrFail($data['stock_warehouse_id']) : null,
                    fromInvoice: isset($data['sales_invoice_id']) ? SalesInvoice::findOrFail($data['sales_invoice_id']) : null,
                );

                // ⭐ B20: the split refund, row by row, in the same transaction.
                foreach (array_values($data['refunds'] ?? []) as $i => $row) {
                    $this->refundRow($refundAction, $return, $row, $i, $data['returned_at'] ?? null, $request);
                }

                if (isset($data['refund'])) {
                    $refundAccount = MoneyAccount::findOrFail($data['refund']['money_account_id']);

                    // ⚠⚠ The refund action refuses on ITS keys (`amount`,
                    // `money_account_id`), and this form's fields are `refund.*`.
                    // So "only 450.00 left to refund" was written on a key the
                    // page never rendered, and Record return looked like it did
                    // nothing (audit #1, 21 Sep 2026). Re-keyed onto the fields
                    // the cashier typed into; thrown inside the transaction, so
                    // the return rolls back with it as before.
                    try {
                        $refundAction->handle(
                            salesReturn: $return,
                            moneyAccount: $refundAccount,
                            amount: (string) $data['refund']['amount'],
                            createdBy: $request->user(),
                            refundedOn: $data['returned_at'] ?? null,
                            reference: $return->return_number,
                        );
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(collect($e->errors())
                            ->mapWithKeys(fn (array $messages, string $key) => [
                                (in_array($key, ['amount', 'money_account_id'], true) ? "refund.{$key}" : $key) => $messages,
                            ])->all());
                    } catch (MappingNotFound $e) {
                        throw ValidationException::withMessages([
                            'refund.money_account_id' => MoneyAccountRefusal::sentence($refundAccount, $e),
                        ]);
                    }
                }

                return $return;
            });
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        // ⭐ B20 (Joy): an exchange is never mixed into the return. The return
        // is done; the replacement is a brand new order, prefilled, linked.
        if (! empty($data['exchange']) && $request->user()->can('sales.orders.record')) {
            return redirect()->route('sales.orders.index', ['exchange' => $return->id])
                ->with('success', "Return {$return->return_number} recorded. Now the replacement order.");
        }

        return redirect()->route('sales.returns.show', $return)
            ->with('success', "Return {$return->return_number} recorded.");
    }

    /**
     * ⭐ B20: one row of a split refund. No amount = all that is still open.
     *
     * @param  array{money_account_id: int|string, amount?: string|int|float|null}  $row
     */
    private function refundRow(RecordCustomerRefund $refundAction, SalesReturn $return, array $row, int $index, ?string $on, Request $request): void
    {
        $account = MoneyAccount::findOrFail($row['money_account_id']);
        $amount = isset($row['amount']) && $row['amount'] !== '' ? (string) $row['amount'] : $return->fresh()->refundable()->toTaka();

        if ((float) $amount <= 0) {
            throw ValidationException::withMessages([
                "refunds.{$index}.amount" => 'Nothing is left to refund: the credit already reduced what the customer owes.',
            ]);
        }

        try {
            $refundAction->handle(
                salesReturn: $return->fresh(),
                moneyAccount: $account,
                amount: $amount,
                createdBy: $request->user(),
                refundedOn: $on,
                reference: $return->return_number,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [
                    (in_array($key, ['amount', 'money_account_id'], true) ? "refunds.{$index}.{$key}" : $key) => $messages,
                ])->all());
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages([
                "refunds.{$index}.money_account_id" => MoneyAccountRefusal::sentence($account, $e),
            ]);
        }
    }

    public function show(Request $request, SalesReturn $salesReturn): Response
    {
        $salesReturn->load([
            'customer:id,name',
            'delivery:id,delivery_number,delivered_at',
            'lines.deliveryLine.product:id,name,sku',
            'allocations.invoice:id,invoice_number',
            'refunds.moneyAccount:id,name',
            // ⭐ B20: where it was opened from, where good goods went, and the
            // replacement order of an exchange.
            'invoice:id,invoice_number',
            'stockWarehouse:id,name',
            'exchangeOrders:id,order_number,exchange_for_sales_return_id',
        ]);

        $journal = Journal::query()
            ->where('source_type', $salesReturn->getMorphClass())
            ->where('source_id', $salesReturn->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        // ⚠ SAL-004 (audit 14 Oct 2026): the cost a return put back or wrote
        // off is for cost readers only (sales.reports.costs).
        if (! CostVisibility::allows($request->user())) {
            $salesReturn->lines->each->makeHidden(['cost_restored', 'cost_written_off', 'gdni_relief']);
        }

        return Inertia::render('sales/returns/show', [
            'salesReturn' => $salesReturn,
            'refundedTotal' => $salesReturn->refundedTotal()->toTaka(),
            'refundable' => $salesReturn->refundable()->toTaka(),
            // The journal on the document, as everywhere else: "show me what
            // this did to the books" is the trust-building question.
            'journal' => CostVisibility::journal($journal, $request->user()),
            'can_refund' => $request->user()->can('sales.returns.refund'),
            // ⚠ Only accounts a refund can be booked out of (see withLedger).
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            // ⭐ B20: the customer's other open invoices the open credit can
            // pay, and whether a replacement order may be started from here.
            'openInvoices' => $request->user()->can('sales.returns.refund') && $salesReturn->refundable()->minor > 0
                ? SalesInvoice::query()->where('customer_id', $salesReturn->customer_id)->whereNull('reversed_at')->unsettled()
                    ->orderBy('invoice_date')->orderBy('id')->limit(50)->get()
                    ->map(fn (SalesInvoice $invoice) => [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date?->toDateString(),
                        'outstanding' => $invoice->outstanding()->toTaka(),
                    ])->values()
                : [],
            'can_exchange' => $request->user()->can('sales.orders.record'),
        ]);
    }

    /**
     * ⭐ B20: use a return's open credit against another open invoice of the
     * same customer (ApplySalesReturnCredit; no journal, see there).
     */
    public function applyCredit(Request $request, SalesReturn $salesReturn, ApplySalesReturnCredit $action): RedirectResponse
    {
        $data = $request->validate([
            'sales_invoice_id' => ['required', 'integer', Rule::exists('sales_invoices', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'open_seen' => ['nullable', 'numeric'],
        ]);

        $invoice = SalesInvoice::findOrFail($data['sales_invoice_id']);
        $action->handle($salesReturn, $invoice, (string) $data['amount'], isset($data['open_seen']) ? (string) $data['open_seen'] : null);

        return back()->with('success', "Credit of {$salesReturn->return_number} used on invoice {$invoice->invoice_number}.");
    }

    /**
     * Money physically going back, later - the non-counter case.
     */
    public function refund(Request $request, SalesReturn $salesReturn, RecordCustomerRefund $action): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'refunded_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ], [
            'refunded_on.before_or_equal' => 'A refund cannot be dated in the future.',
        ]);

        // ⚠ C-SLS-E1. Cash going back into a closed month is refused; the date
        // field is the one the user can actually change.
        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        try {
            $action->handle(
                salesReturn: $salesReturn,
                moneyAccount: $moneyAccount,
                amount: (string) $data['amount'],
                createdBy: $request->user(),
                refundedOn: $data['refunded_on'],
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['refunded_on' => $e->getMessage()]);
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($moneyAccount, $e)]);
        }

        return back()->with('success', 'Refund recorded.');
    }
}
