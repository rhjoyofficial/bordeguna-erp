<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->withQueryString();

        $returns->getCollection()->transform(function (SalesReturn $return) {
            $return->setAttribute('refunded_total', $return->refundedTotal()->toTaka());

            return $return;
        });

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

        if ($deliveryId !== null) {
            $delivery = Delivery::query()
                ->with(['customer:id,name', 'lines.product:id,name,sku'])
                ->findOrFail($deliveryId);

            $lines = $delivery->lines->map(fn ($line) => [
                'delivery_line_id' => $line->id,
                'product_name' => $line->product->name,
                'product_sku' => $line->product->sku,
                'quantity_delivered' => (string) $line->quantity,
                'quantity_returnable' => $line->quantityReturnable(),
                // How much of a return would relieve GDNI vs credit AR - shown
                // so the split is never a surprise, decided by the engine.
                'quantity_uninvoiced' => $line->quantityUninvoiced(),
            ])->values()->all();
        }

        // Challans with anything left to return, newest first, for the picker.
        $deliveries = Delivery::query()
            ->with('customer:id,name')
            ->whereHas('lines', fn ($q) => $q->whereColumn('quantity_returned', '<', 'quantity'))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'delivery_number', 'delivered_at', 'customer_id']);

        return Inertia::render('sales/returns/create', [
            'deliveries' => $deliveries,
            'deliveryId' => $deliveryId,
            'delivery' => $delivery?->only(['id', 'delivery_number', 'customer']),
            'lines' => $lines,
            'can_refund' => $request->user()->can('sales.returns.refund'),
            'moneyAccounts' => MoneyAccount::query()->where('is_active', true)
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
            'returned_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['required', 'integer', 'exists:delivery_lines,id'],
            'lines.*.quantity_to_stock' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_written_off' => ['nullable', 'numeric', 'min:0'],
            // The counter case (C-SR-6): cash back in hand, same transaction.
            'refund' => ['nullable', 'array'],
            'refund.money_account_id' => ['required_with:refund', 'integer', 'exists:money_accounts,id'],
            'refund.amount' => ['required_with:refund', 'numeric', 'gt:0'],
        ]);

        // ⚠ Recording is Staff-level; CASH going back is not (C-SR-9).
        if (isset($data['refund']) && ! $request->user()->can('sales.returns.refund')) {
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
            ];
        })->all();

        // ⚠ Return and instant refund are ONE transaction: nobody hands cash
        // over for a return that then fails to record.
        $return = DB::transaction(function () use ($action, $refundAction, $delivery, $lines, $data, $request) {
            $return = $action->handle(
                delivery: $delivery,
                lines: $lines,
                createdBy: $request->user(),
                returnedAt: $data['returned_at'] ?? null,
                note: $data['note'] ?? null,
            );

            if (isset($data['refund'])) {
                $refundAction->handle(
                    salesReturn: $return,
                    moneyAccount: MoneyAccount::findOrFail($data['refund']['money_account_id']),
                    amount: (string) $data['refund']['amount'],
                    createdBy: $request->user(),
                    refundedOn: $data['returned_at'] ?? null,
                    reference: $return->return_number,
                );
            }

            return $return;
        });

        return redirect()->route('sales.returns.show', $return)
            ->with('success', "Return {$return->return_number} recorded.");
    }

    public function show(Request $request, SalesReturn $salesReturn): Response
    {
        $salesReturn->load([
            'customer:id,name',
            'delivery:id,delivery_number,delivered_at',
            'lines.deliveryLine.product:id,name,sku',
            'allocations.invoice:id,invoice_number',
            'refunds.moneyAccount:id,name',
        ]);

        $journal = Journal::query()
            ->where('source_type', $salesReturn->getMorphClass())
            ->where('source_id', $salesReturn->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        return Inertia::render('sales/returns/show', [
            'salesReturn' => $salesReturn,
            'refundedTotal' => $salesReturn->refundedTotal()->toTaka(),
            'refundable' => $salesReturn->refundable()->toTaka(),
            // The journal on the document, as everywhere else: "show me what
            // this did to the books" is the trust-building question.
            'journal' => $journal,
            'can_refund' => $request->user()->can('sales.returns.refund'),
            'moneyAccounts' => MoneyAccount::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Money physically going back, later - the non-counter case.
     */
    public function refund(Request $request, SalesReturn $salesReturn, RecordCustomerRefund $action): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'refunded_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            salesReturn: $salesReturn,
            moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
            amount: (string) $data['amount'],
            createdBy: $request->user(),
            refundedOn: $data['refunded_on'],
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Refund recorded.');
    }
}
