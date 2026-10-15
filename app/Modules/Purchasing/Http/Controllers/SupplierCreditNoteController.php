<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Actions\ReverseSupplierCreditNote;
use App\Modules\Purchasing\Http\Controllers\Concerns\ReversesDocuments;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Support\PurchasesPulse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen over RecordSupplierCreditNote.
 *
 * ⚠ Decides nothing about money, exactly as SupplierPaymentController does
 * not: the period lock, the return-cap, the per-bill outstanding refusal and
 * the posting all live in the action. This collects a form, validates its
 * SHAPE, scopes every `exists:` to the business, and renders the action's own
 * refusals.
 */
class SupplierCreditNoteController extends Controller
{
    use ReversesDocuments;

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            // Purchases redesign (15 Oct 2026): the Posted / Reversed chips.
            'state' => $request->string('state')->toString(),
        ];

        $creditNotes = SupplierCreditNote::query()
            ->with(['supplier:id,name'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('credit_note_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
            ))
            ->when($filters['supplier_id'], fn ($query, $id) => $query->where('supplier_id', (int) $id))
            ->when($filters['state'] === 'reversed', fn ($query) => $query->whereNotNull('reversed_at'))
            ->when($filters['state'] === 'posted', fn ($query) => $query->whereNull('reversed_at'))
            ->orderByDesc('credit_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $creditNotes->getCollection()->transform(function (SupplierCreditNote $creditNote) {
            $creditNote->setAttribute('credit_date_only', $creditNote->credit_date?->toDateString());
            $creditNote->setAttribute('total_taka', Money::ofTaka((string) $creditNote->total)->toTaka());

            return $creditNote;
        });

        return Inertia::render('purchasing/credit-notes/index', [
            'creditNotes' => $creditNotes,
            'filters' => $filters,
            'counts' => [
                'all' => SupplierCreditNote::query()->count(),
                'reversed' => SupplierCreditNote::query()->whereNotNull('reversed_at')->count(),
            ],
            // Purchases redesign (15 Oct 2026): the same money strip as Bills and Payments.
            'strip' => PurchasesPulse::moneyStrip(Carbon::today()),
            'can_record' => $request->user()->can('purchasing.credit_note.record'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The form: a supplier's returns that still have value to credit, and
     * their bills that are still open.
     */
    public function create(Request $request): Response
    {
        $supplierId = $request->integer('supplier_id') ?: null;
        $supplier = $supplierId === null ? null : Supplier::query()->find($supplierId);

        return Inertia::render('purchasing/credit-notes/create', [
            // VAT plan D7: false today, and the VAT field is then not shown.
            'vatApplies' => Business::query()->find(app(BusinessContext::class)->id())?->vatAppliesOn(Carbon::today()) ?? false,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'selectedSupplierId' => $supplier?->id,
            'returns' => $supplier === null ? [] : $this->creditableReturnsFor($supplier),
            'bills' => $supplier === null ? [] : $this->openBillsFor($supplier),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordSupplierCreditNote $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')
                ->where('business_id', $businessId)],
            'purchase_return_id' => ['required', 'integer', Rule::exists('purchase_returns', 'id')
                ->where('business_id', $businessId)],
            'credit_note_number' => ['required', 'string', 'max:100'],
            'credit_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string'],
            // The VAT the supplier is giving back, INSIDE the amounts below.
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'integer', 'distinct'],
            // ⚠ gte:0 - the form sends a row per open bill; zero means "not
            // this one". The action drops zeros and refuses an empty credit.
            'allocations.*.amount' => ['required', 'numeric', 'gte:0'],
        ], [
            'credit_date.before_or_equal' => 'A credit note cannot be dated in the future.',
        ]);

        $supplier = Supplier::findOrFail($data['supplier_id']);
        $purchaseReturn = PurchaseReturn::findOrFail($data['purchase_return_id']);

        $allocations = [];

        foreach ($data['allocations'] as $index => $row) {
            $bill = SupplierBill::find($row['supplier_bill_id']);

            if ($bill === null) {
                // ⚠ C-PUR-E2. Neither screen renders a per-line bill key, so
                // this landed nowhere. The line number goes into the sentence
                // instead, which is what "WHICH line is wrong" needed anyway.
                return back()->withErrors([
                    'allocations' => 'Line '.($index + 1).': that bill is not available in this business.',
                ]);
            }

            $allocations[] = ['bill' => $bill, 'amount' => (string) $row['amount']];
        }

        try {
            $creditNote = $action->handle(
                supplier: $supplier,
                purchaseReturn: $purchaseReturn,
                creditNoteNumber: $data['credit_note_number'],
                creditDate: $data['credit_date'],
                allocations: $allocations,
                createdBy: $request->user(),
                note: $data['note'] ?? null,
                taxAmount: (string) ($data['tax_amount'] ?? '0'),
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['credit_date' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchasing.credit-notes.show', $creditNote->id)
            ->with('success', 'Credit note recorded.');
    }

    /**
     * PUR-003 (owner decision 14 Oct 2026): the correction is a reversal with a
     * reason, gated like recording a credit note.
     */
    public function reverse(Request $request, SupplierCreditNote $creditNote, ReverseSupplierCreditNote $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($creditNote, $request->user(), $reason, $onOriginalDate),
            "Credit note {$creditNote->credit_note_number} reversed. The bills it credited owe again.",
        );
    }

    public function show(Request $request, SupplierCreditNote $creditNote): Response
    {
        $creditNote->load([
            'supplier:id,name,code',
            'purchaseReturn:id,purchase_order_id,returned_at',
            'createdBy:id,name',
            'allocations.bill:id,bill_number,bill_date,total',
        ]);

        $allocations = $creditNote->allocations->map(function ($allocation) {
            $bill = $allocation->bill;

            return [
                'id' => $allocation->id,
                'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
                'bill' => $bill === null ? null : [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                    'outstanding' => $bill->outstanding()->toTaka(),
                ],
            ];
        });

        return Inertia::render('purchasing/credit-notes/show', [
            'creditNote' => array_merge($creditNote->toArray(), [
                'credit_date' => $creditNote->credit_date?->toDateString(),
                'total' => Money::ofTaka((string) $creditNote->total)->toTaka(),
                // PUR-003 (14 Oct 2026): posted or reversed, and why.
                'status' => $creditNote->status(),
                'reversal' => $creditNote->reversalSummary(),
            ]),
            'allocations' => $allocations,
            'can_reverse' => ! $creditNote->isReversed() && $request->user()->can('purchasing.credit_note.record'),
            'journal' => Journal::query()
                ->where('source_type', $creditNote->getMorphClass())
                ->where('source_id', $creditNote->id)
                ->whereNull('reverses_journal_id')
                ->with('lines.account:id,code,name')
                ->first(),
        ]);
    }

    /**
     * Returns that still have value left to credit, with the ceiling shown.
     *
     * @return array<int, array<string, mixed>>
     */
    private function creditableReturnsFor(Supplier $supplier): array
    {
        return PurchaseReturn::query()
            ->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $supplier->id))
            ->with(['lines.stockMovement', 'lines.purchaseOrderLine'])
            ->orderByDesc('returned_at')
            ->get()
            ->map(fn (PurchaseReturn $return) => [
                'id' => $return->id,
                'returned_at' => $return->returned_at?->toDateString(),
                'creditable' => $return->invoiceableValue()
                    ->plus($return->creditedTotal()->negated())
                    ->toTaka(),
            ])
            ->filter(fn (array $row) => Money::ofTaka($row['creditable'])->minor > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openBillsFor(Supplier $supplier): array
    {
        return SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'total' => $bill->total,
                'outstanding' => $bill->outstanding()->toTaka(),
            ])
            ->filter(fn (array $row) => Money::ofTaka($row['outstanding'])->minor > 0)
            ->values()
            ->all();
    }
}
