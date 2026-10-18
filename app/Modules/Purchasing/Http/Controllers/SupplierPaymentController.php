<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\Http\Controllers\Concerns\ReversesDocuments;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\PurchasesPulse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen over an engine that already existed.
 *
 * ⚠⚠ THIS CONTROLLER DECIDES NOTHING ABOUT MONEY. Every rule that matters -
 * the accounting period lock, the cross-business check, re-reading each bill
 * under a lock inside the transaction, refusing to pay more than is outstanding,
 * and posting Dr Accounts Payable / Cr the money account through PostJournal -
 * lives in RecordSupplierPayment and stays there. This class collects a form,
 * validates its SHAPE, and hands it over.
 *
 * ▶ Why that matters: the action is the only path that writes a payment, so a
 * second set of rules here would be a second payment engine that drifts from
 * the first. The validation below deliberately stops short of the money rules
 * for that reason - it checks that a number is a number, not whether the
 * business may pay it.
 *
 * ⚠ The one thing it does decide is OWNERSHIP: every `exists:` here is scoped to
 * the current business, because a bare one accepts another business's id - the
 * hole found on the product screen on 5 August. That is a routing concern rather
 * than an accounting one, which is why it belongs here and not in the action.
 */
class SupplierPaymentController extends Controller
{
    use ReversesDocuments;

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            // Purchases redesign (15 Oct 2026): "Paid in October" on the money
            // strip opens the month; the chips offer this month and last.
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'state' => $request->string('state')->toString(),
        ];

        $payments = SupplierPayment::query()
            ->with(['supplier:id,name', 'moneyAccount:id,name'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
            ))
            ->when($filters['supplier_id'], fn ($query, $id) => $query->where('supplier_id', (int) $id))
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('paid_on', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('paid_on', '<=', $to))
            ->when($filters['state'] === 'reversed', fn ($query) => $query->whereNotNull('reversed_at'))
            ->when($filters['state'] === 'posted', fn ($query) => $query->whereNull('reversed_at'))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // ⚠ Same date and money corrections as the detail page. A list that
        // disagrees with the document it links to is worse than either alone.
        $payments->getCollection()->transform(function (SupplierPayment $payment) {
            $payment->setAttribute('paid_on_date', $payment->paid_on?->toDateString());
            $payment->setAttribute('amount_taka', Money::ofTaka((string) $payment->amount)->toTaka());

            return $payment;
        });

        // ⚠ PUR-007 (audit 14 Oct 2026): the Tax rates page sits behind
        // purchasing.suppliers.manage. Since the Purchases redesign (15 Oct
        // 2026) it is a tab under Suppliers, gated by the shared
        // canManageSuppliers flag, so this screen no longer offers a button.
        return Inertia::render('purchasing/payments/index', [
            'payments' => $payments,
            'filters' => $filters,
            'counts' => [
                'all' => SupplierPayment::query()->count(),
                'reversed' => SupplierPayment::query()->whereNotNull('reversed_at')->count(),
            ],
            'strip' => PurchasesPulse::moneyStrip(Carbon::today()),
            'today' => Carbon::today()->toDateString(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The form. Shows every supplier that is actually owed something, and for a
     * chosen supplier, the bills that are still open.
     */
    public function create(Request $request): Response
    {
        $supplierId = $request->integer('supplier_id') ?: null;

        // "Pay this bill" from a bill's page (21 Sep 2026). ⚠ Resolved through
        // the SCOPED model, so another business's bill id finds nothing, and the
        // bill decides the supplier: a bill can only be paid to whoever issued
        // it. It PREFILLS the amount and nothing more; the clerk still chooses
        // the account and presses the button, and RecordSupplierPayment still
        // re-checks everything.
        $prefillBill = $request->integer('bill_id')
            ? SupplierBill::query()->find($request->integer('bill_id'))
            : null;

        if ($prefillBill !== null && $prefillBill->outstanding()->minor <= 0) {
            $prefillBill = null;
        }

        if ($prefillBill !== null) {
            $supplierId = $prefillBill->supplier_id;
        }

        $supplier = $supplierId === null
            ? null
            : Supplier::query()->find($supplierId);

        return Inertia::render('purchasing/payments/create', [
            'suppliers' => $this->suppliersWithOutstanding(),
            'selectedSupplierId' => $supplier?->id,
            'bills' => $supplier === null ? [] : $this->openBillsFor($supplier),
            'prefill' => $prefillBill === null ? null : [
                'bill_id' => $prefillBill->id,
                'amount' => $prefillBill->outstanding()->toTaka(),
            ],
            // ⚠ Only accounts the payment can be booked out of: one with no
            // ledger head is refused by PostPurchaseJournals on submit.
            // ⭐ F20 (practice run, 17 Oct 2026): each with what is in it now,
            // so the payer sees whether BRAC has enough before paying.
            'moneyAccounts' => tap(MoneyAccount::query()->handled()->withLedger()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'business_id', 'name', 'type', 'account_id']), function ($accounts) {
                    $balances = MoneyAccountBalances::for($accounts);
                    $accounts->each(fn (MoneyAccount $account) => $account->setAttribute('balance', $balances[$account->id] ?? null)
                        ->makeHidden(['business_id', 'account_id']));
                }),
            // Finance decision C4: the TDS / VDS rates the owner keeps, with the
            // supplier types each applies to (null = any).
            'withholdingRates' => WithholdingRate::query()->orderBy('kind')->orderBy('name')->get()
                ->map(fn (WithholdingRate $r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'label' => $r->label(), 'rate' => (float) $r->rate,
                    'supplier_type' => $r->supplier_type,
                    'from' => $r->effective_from?->toDateString(), 'to' => $r->effective_to?->toDateString(),
                ]),
            'supplierType' => $supplier?->supplier_type,
            'today' => now()->toDateString(),
            // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the "Discount
            // received" box is offered only to who may take one.
            'canSettleDiscount' => $request->user()->can('purchasing.discount.settle'),
        ]);
    }

    public function store(Request $request, RecordSupplierPayment $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ Scoped exists, not a bare one. A bare `exists:suppliers,id`
            // accepts another business's supplier, and the global scope would
            // then hide the row the payment points at. Same hole that was found
            // and closed on the product screen on 5 August.
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')
                ->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')
                ->where('business_id', $businessId)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            // ⚠⚠ `distinct` is not the safety net, it is the error message. The
            // same bill twice in one submission is already impossible: the
            // allocations table is unique on (supplier_payment_id,
            // supplier_bill_id) and the whole write is one transaction, so the
            // data was never at risk. Without this rule the clerk simply gets a
            // raw 500 from the constraint instead of a sentence naming the line.
            // Verified by deleting it and watching the SQLSTATE 23000 arrive.
            'allocations.*.supplier_bill_id' => ['required', 'integer', 'distinct'],
            // ⚠ `gte:0`, not `gt:0`. The form sends a row per open bill and the
            // clerk fills in the ones being paid; a zero row is "not this one",
            // not an error. The action drops them and then refuses a payment
            // that allocates nothing at all, which is the real rule.
            'allocations.*.amount' => ['required', 'numeric', 'gte:0'],
            'tds_rate_id' => ['nullable', 'integer', Rule::exists('withholding_rates', 'id')->where('business_id', $businessId)->where('kind', 'tds')],
            'vds_rate_id' => ['nullable', 'integer', Rule::exists('withholding_rates', 'id')->where('business_id', $businessId)->where('kind', 'vds')],
            // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a settlement
            // discount per bill, with the evidence of the agreement and why.
            'allocations.*.discount' => ['nullable', 'numeric', 'gte:0'],
            'discount_evidence' => ['nullable', 'string', 'max:120'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'paid_on.before_or_equal' => 'A payment cannot be dated in the future.',
        ]);

        $supplier = Supplier::findOrFail($data['supplier_id']);
        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        $allocations = [];

        foreach ($data['allocations'] as $index => $row) {
            $bill = SupplierBill::find($row['supplier_bill_id']);

            // ⚠ A bill from another business fails the global scope and comes
            // back null. Reported as a validation error on the row rather than
            // a 404, because the operator needs to know WHICH line is wrong.
            if ($bill === null) {
                // ⚠ C-PUR-E2. Neither screen renders a per-line bill key, so
                // this landed nowhere. The line number goes into the sentence
                // instead, which is what "WHICH line is wrong" needed anyway.
                return back()->withErrors([
                    'allocations' => 'Line '.($index + 1).': that bill is not available in this business.',
                ]);
            }

            $allocations[] = ['bill' => $bill, 'amount' => (string) $row['amount'], 'discount' => (string) ($row['discount'] ?? '0')];

            // ⭐ Supplier discounts: a discount rides on money paid to that bill.
            if ((float) ($row['discount'] ?? 0) > 0 && (float) $row['amount'] <= 0) {
                throw ValidationException::withMessages(["allocations.{$index}.discount" => "Bill {$bill->bill_number}: a settlement discount is taken with a payment on the same bill. Type what is paid as well."]);
            }
        }

        $discounting = collect($allocations)->contains(fn (array $row) => (float) $row['discount'] > 0);

        if ($discounting && trim((string) ($data['discount_evidence'] ?? '')) === '') {
            throw ValidationException::withMessages(['discount_evidence' => "Give the evidence of the supplier's agreement to the discount (their letter, message or document number)."]);
        }

        if ($discounting && trim((string) ($data['discount_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['discount_reason' => 'Say why the supplier allowed the discount.']);
        }

        // ⚠⚠ The action's own refusals are RENDERED, never re-decided. A
        // ValidationException it raises - overpayment, a bill belonging to
        // another supplier - passes straight through untouched, with its own
        // wording, and lands on the right field.
        //
        // ⚠ PeriodClosedException and MappingNotFound are plain RuntimeExceptions,
        // so without this they reach the user as a raw 500 page. Caught here and
        // turned into a sentence on the form, exactly as JournalController does
        // it. This adds no rule: the decision was made in the engine, and all
        // that happens here is that a human can read it.
        try {
            $payment = $action->handle(
                supplier: $supplier,
                moneyAccount: $moneyAccount,
                paidOn: $data['paid_on'],
                allocations: $allocations,
                createdBy: $request->user(),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
                tdsRate: empty($data['tds_rate_id']) ? null : WithholdingRate::find($data['tds_rate_id']),
                vdsRate: empty($data['vds_rate_id']) ? null : WithholdingRate::find($data['vds_rate_id']),
                discountEvidence: $data['discount_evidence'] ?? null,
                discountReason: $data['discount_reason'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['paid_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchasing.payments.show', $payment->id)
            ->with('success', 'Payment recorded.');
    }

    /**
     * PUR-003 (owner decision 14 Oct 2026): the correction is a reversal with a
     * reason, gated like recording a payment.
     */
    public function reverse(Request $request, SupplierPayment $payment, ReverseSupplierPayment $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($payment, $request->user(), $reason, $onOriginalDate),
            "Payment #{$payment->id} reversed. The bills it paid are open again.",
        );
    }

    public function show(Request $request, SupplierPayment $payment): Response
    {
        $payment->load([
            'supplier:id,name,code',
            'moneyAccount:id,name,type',
            'createdBy:id,name',
            'allocations.bill:id,bill_number,bill_date,due_date,total',
        ]);

        // ⚠ Outstanding is recomputed per bill AS IT IS NOW, not as it was when
        // this payment was made. That is the honest figure to show next to a
        // historical allocation: "you paid 500 of this bill, and 200 is still
        // owed today".
        $allocations = $payment->allocations->map(function ($allocation) {
            $bill = $allocation->bill;

            return [
                'id' => $allocation->id,
                'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
                'bill' => $bill === null ? null : [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    // ⚠⚠ toDateString(), not the model's date cast. A `date`
                    // cast serialises to JSON in UTC, and Dhaka is UTC+6, so
                    // midnight local becomes 18:00 the PREVIOUS day - a bill
                    // dated the 8th displayed as the 7th. Caught in a browser;
                    // the database was correct throughout.
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                    'outstanding' => $bill->outstanding()->toTaka(),
                ],
            ];
        });

        return Inertia::render('purchasing/payments/show', [
            'payment' => array_merge($payment->toArray(), [
                // ⚠ Same two corrections on the document itself: a calendar date
                // that cannot shift, and money formatted rather than the raw
                // decimal(15,4) the column holds (which reads "750.0000").
                'paid_on' => $payment->paid_on?->toDateString(),
                'amount' => Money::ofTaka((string) $payment->amount)->toTaka(),
                'tds_amount' => Money::ofTaka((string) $payment->tds_amount)->toTaka(),
                'vds_amount' => Money::ofTaka((string) $payment->vds_amount)->toTaka(),
                'net_paid' => $payment->netPaid()->toTaka(),
                // PUR-003 (14 Oct 2026): posted or reversed, and the reversal's
                // who, when and why, with the bills it had settled.
                'status' => $payment->status(),
                'reversal' => $payment->reversalSummary(),
            ]),
            'allocations' => $allocations,
            'can_reverse' => ! $payment->isReversed() && $payment->voucher_id === null
                && $request->user()->can('purchasing.payment.record'),
            // ⚠ The journal is shown on the document on purpose, exactly as the
            // sales invoice does it. "Show me what this did to the books" is the
            // question that makes an accountant trust the system, and hiding it
            // behind the ledger screen is how people stop checking.
            'journal' => Journal::query()
                ->where('source_type', $payment->getMorphClass())
                ->where('source_id', $payment->id)
                ->whereNull('reverses_journal_id')
                ->with('lines.account:id,code,name')
                ->first(),
        ]);
    }

    /**
     * Suppliers with at least one bill still owing, and how much in total.
     *
     * @return array<int, array{id:int, name:string, outstanding:string}>
     */
    private function suppliersWithOutstanding(): array
    {
        // ⚠ PUR-008 (audit 14 Oct 2026): one query over the bills that still
        // owe, summed per supplier, instead of every bill of every supplier
        // with three SUM queries each.
        $owed = SupplierBill::query()->owingWithOutstanding()->get(['supplier_bills.supplier_id'])
            ->groupBy('supplier_id')
            ->map(fn ($bills) => Money::ofMinor((int) $bills->sum(fn (SupplierBill $bill) => (int) $bill->outstanding_paisa)));

        return Supplier::query()
            ->whereIn('id', $owed->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'outstanding' => $owed[$supplier->id]->toTaka(),
            ])
            // ⚠ Compared through Money, not by casting the string to a float.
            // A supplier owed exactly nothing must not appear, and float
            // comparison on money is the habit this codebase removed.
            ->filter(fn (array $row) => Money::ofTaka($row['outstanding'])->minor > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openBillsFor(Supplier $supplier): array
    {
        // ⚠ PUR-008: settled and reversed bills are left out in SQL and what
        // each still owes comes back on the same row (SupplierBill scope).
        return SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->owingWithOutstanding()
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                'outstanding' => Money::ofMinor((int) $bill->outstanding_paisa)->toTaka(),
            ])
            ->values()
            ->all();
    }
}
