<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyCard;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Purchasing\Enums\CertificationType;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCertification;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Modules\Purchasing\Support\SupplierCredit;
use App\Modules\Purchasing\Support\SupplierTypes;
use App\Support\Documents\PartyDocuments;
use App\Support\RecordTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * ⚠ Bank details, BIN and TIN are only sent to users who may manage
     * suppliers. Viewing the list is open to the whole business, but an
     * account number is not something everyone needs in their page source.
     */
    private const SENSITIVE = [
        'bin', 'tin', 'bank_name', 'bank_branch',
        'bank_account_name', 'bank_account_number', 'mfs_number', 'notes',
    ];

    /**
     * The supplier 360 (C-360): one screen that answers "tell me about this
     * supplier". The payable is the SAME identity Ageing::payables uses -
     * bills − payments − credit notes - summed from the documents, so this
     * page can never disagree with the ageing.
     *
     * ⚠ Bank details stay OFF this page (C-360-5). The list deliberately hides
     * them behind suppliers.manage, and a profile must not be the side door.
     */
    public function show(Supplier $supplier): Response
    {
        $bills = SupplierBill::query()->where('supplier_id', $supplier->id)
            ->orderBy('bill_date')->orderBy('id')->get();
        $payments = SupplierPayment::query()->where('supplier_id', $supplier->id)->get();
        $credits = SupplierCreditNote::query()->where('supplier_id', $supplier->id)->get();

        // ⚠ PUR-001 (audit 14 Oct 2026): a reversed bill is not billed.
        $billed = Money::sum($bills->reject(fn (SupplierBill $bill) => $bill->isReversed())
            ->map(fn (SupplierBill $bill) => Money::ofTaka((string) $bill->total)));
        // PUR-003 (14 Oct 2026): nor is a reversed payment paid or a reversed
        // credit note credited.
        $paid = Money::sum($payments->reject(fn (SupplierPayment $payment) => $payment->isReversed())
            ->map(fn (SupplierPayment $payment) => Money::ofTaka((string) $payment->amount)));
        $credited = Money::sum($credits->reject(fn (SupplierCreditNote $credit) => $credit->isReversed())
            ->map(fn (SupplierCreditNote $credit) => Money::ofTaka((string) $credit->total)));

        $today = Carbon::today();

        // ⚠⚠ PUR-001 (audit 14 Oct 2026): "We owe now" IS the AP ageing's
        // figure for this supplier, taken from the ageing itself. Summed here
        // by hand it counted reversed bills and left out discounts and
        // set-offs, so it could say a supplier was owed money when the ledger,
        // the ageing and the payment form all said nothing was.
        $payable = Ageing::payables($today)->rows->firstWhere('partyId', $supplier->id)?->total() ?? Money::zero();

        $openBills = $bills
            ->filter(fn (SupplierBill $bill) => $bill->outstanding()->minor > 0)
            ->values()
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'total' => Money::ofTaka((string) $bill->total)->format(),
                'outstanding' => $bill->outstanding()->format(),
                // ⚠ Date-string comparison, the recurring trap.
                'days_overdue' => ($bill->due_date !== null && $bill->due_date->toDateString() < $today->toDateString())
                    ? (int) Carbon::parse($bill->due_date->toDateString())->diffInDays($today)
                    : 0,
            ]);

        $purchaseOrders = PurchaseOrder::query()->where('supplier_id', $supplier->id)->get();
        $receipts = GoodsReceipt::query()
            ->whereIn('purchase_order_id', $purchaseOrders->pluck('id'))
            ->with('purchaseOrder:id,po_number')
            ->get();

        // The document history, newest first: what happened with this
        // supplier, in the words the documents themselves use.
        $recent = collect()
            ->concat($bills->map(fn (SupplierBill $bill) => [
                'date' => $bill->bill_date?->toDateString(),
                'kind' => 'bill',
                'label' => "Bill {$bill->bill_number}",
                'amount' => Money::ofTaka((string) $bill->total)->format(),
            ]))
            ->concat($payments->map(fn (SupplierPayment $payment) => [
                'date' => $payment->paid_on?->toDateString(),
                'kind' => 'payment',
                'label' => $payment->isReversed() ? "Payment made, reversed on {$payment->reversed_on?->toDateString()}" : 'Payment made',
                'amount' => '-'.Money::ofTaka((string) $payment->amount)->format(),
            ]))
            ->concat($credits->map(fn (SupplierCreditNote $credit) => [
                'date' => $credit->credit_date?->toDateString(),
                'kind' => 'credit',
                'label' => $credit->isReversed()
                    ? "Credit note {$credit->credit_note_number}, reversed on {$credit->reversed_on?->toDateString()}"
                    : "Credit note {$credit->credit_note_number}",
                'amount' => '-'.Money::ofTaka((string) $credit->total)->format(),
            ]))
            ->concat($purchaseOrders->map(fn (PurchaseOrder $po) => [
                // ⭐ D-G, 18 Oct 2026: the day it was placed, not the day it was typed in.
                'date' => $po->order_date?->toDateString() ?? $po->created_at?->toDateString(),
                'kind' => 'po',
                'label' => "PO {$po->po_number} ({$po->status->value})",
                'amount' => null,
            ]))
            ->concat($receipts->map(fn (GoodsReceipt $receipt) => [
                'date' => $receipt->received_at?->toDateString(),
                'kind' => 'receipt',
                'label' => 'Goods received against PO '.($receipt->purchaseOrder?->po_number ?? '?'),
                'amount' => null,
            ]))
            ->sortByDesc('date')
            ->take(15)
            ->values();

        // ⭐ PR5 (18 Oct 2026): EVERY document with this supplier, each row
        // opening its own page (Tally's drill-down), paged, by type.
        $all = PartyDocuments::page(
            PartyDocuments::forSupplier($supplier, request()->user()),
            request()->string('doc_type')->toString(),
            request()->integer('docs_page', 1),
            '/purchasing/suppliers/'.$supplier->id,
        );

        return Inertia::render('purchasing/suppliers/show', [
            'all_documents' => $all,
            // ⭐ Supplier advances (ruling P2): what this supplier holds of ours,
            // shown apart from what we owe them, never netted into it.
            'advances_open' => SupplierAdvanceBook::totalOpen($supplier)->toTaka(),
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'payment_terms' => $supplier->payment_terms,
                'is_active' => $supplier->is_active,
            ],
            // ⭐ Ruling P6: the structured terms and the credit warning, each
            // part apart (billed, received not billed, open orders, advances).
            'credit' => SupplierCredit::for($supplier),
            'payable' => [
                'amount' => $payable->format(),
                'is_credit' => $payable->minor < 0,
            ],
            'totals' => [
                'billed' => $billed->format(),
                'paid' => $paid->format(),
                'credited' => $credited->format(),
                'open_pos' => $purchaseOrders->filter(fn (PurchaseOrder $po) => in_array($po->status->value, ['open', 'partially_received'], true))->count(),
            ],
            'open_bills' => $openBills,
            'recent' => $recent,
            // The record's own story (Module 1, Slice 4).
            'timeline' => RecordTimeline::for($supplier),
            // ⭐ Plan D11: trade licence and NID scans, NID as a count unless the
            // reader may open it.
            'documents' => IdentityDocuments::props($supplier, request()->user()),
            // ⭐ Joy's answer 18: certifications, only when relevant.
            'certifications' => $supplier->certifications()->with('attachments')
                ->orderBy('expires_on')->orderBy('id')->get()
                ->map(fn (SupplierCertification $c) => [
                    'id' => $c->id,
                    'type' => $c->type->value,
                    'label' => $c->label(),
                    'name' => $c->name,
                    'number' => $c->number,
                    'expires_on' => $c->expires_on?->toDateString(),
                    'is_expired' => $c->isExpired(),
                    'scans' => $c->attachments->where('collection', SupplierCertification::SCAN)
                        ->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'url' => $file->url()])
                        ->values()->all(),
                ])->all(),
            'certification_types' => collect(CertificationType::cases())
                ->map(fn (CertificationType $t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'can_manage_certifications' => request()->user()?->can('purchasing.suppliers.manage') ?? false,
            // ⭐ D-F (18 Oct 2026): also our customer? Both sides together.
            'party' => PartyCard::forSupplier($supplier, request()->user()),
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $suppliers = Supplier::query()
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    // ⚠ The code is how purchasing refers to a supplier out loud,
                    // so it has to be searchable or it is not really an identifier.
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $mayManage = $request->user()?->can('purchasing.suppliers.manage') ?? false;

        if (! $mayManage) {
            $suppliers->getCollection()->transform(function (Supplier $supplier) {
                foreach (self::SENSITIVE as $field) {
                    $supplier->offsetUnset($field);
                }

                return $supplier;
            });
        }

        return Inertia::render('purchasing/suppliers/index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
            'mayManage' => $mayManage,
            // Every type with its state: the popup offers the active ones plus
            // whatever the supplier being edited already carries.
            'supplierTypes' => SupplierTypes::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request) + ['is_active' => true]);

        return back()->with('success', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => false]);

        return back()->with('success', 'Supplier deactivated.');
    }

    /**
     * ⚠⚠ The screen and the import must produce the SAME supplier. A supplier
     * typed in by hand must not be structurally different from an imported one -
     * otherwise the import's idempotency key is missing on exactly the records
     * a person created in a hurry, and a re-run duplicates them.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $businessId = $supplier?->business_id ?? app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠⚠ REQUIRED here, though the column stays nullable: the database
            // tolerates the historical rows, the screen refuses to create new
            // ones. Scoped to the business, because two businesses may both
            // have an ACME.
            // ⚠⚠ Frozen once assigned, the same rule as brand codes: the
            // import finds a supplier by its code, so a rename on this screen
            // would make the next import file create a second supplier. A
            // historical row with no code may still be given one, once.
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($supplier?->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($supplier) {
                    if ($supplier?->code !== null && $supplier->code !== '' && $value !== $supplier->code) {
                        $fail("A supplier's code is permanent once set. This one stays {$supplier->code}.");
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            // ⚠ The business's own active types; a switched-off one only stays
            // on the supplier that already has it.
            'supplier_type' => ['nullable', 'string', SupplierTypes::rule($supplier?->supplier_type)],

            // Tax identity. Optional: a supplier is real before anybody knows
            // their BIN.
            'bin' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:255'],

            // How we actually pay them. Operational facts, not accounting.
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'mfs_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],

            // ⭐ Ruling P6: structured terms a bill's due date is worked out
            // from, and a credit limit that WARNS (never blocks).
            ...PaymentTerms::rules(),
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], PaymentTerms::messages());

        // Days mean nothing on "immediate" or "advance"; never store a stray number.
        if (array_key_exists('payment_terms_type', $data)) {
            $data = array_merge($data, PaymentTerms::columns(PaymentTerms::of($data['payment_terms_type'] ?? null, $data['payment_terms_days'] ?? null)));
        }

        return $data;
    }

    /**
     * ⭐ Ruling P6: the credit warning for a supplier, as JSON, for the
     * purchase order form (which learns the supplier only when one is picked).
     * `adding` is the order total being typed. Read gate: the supplier page's.
     */
    public function credit(Request $request, Supplier $supplier): JsonResponse
    {
        $adding = $request->validate(['adding' => ['nullable', 'numeric', 'min:0', 'max:9999999999']])['adding'] ?? null;

        return response()->json(SupplierCredit::for($supplier, $adding === null ? null : Money::ofTaka((string) $adding)));
    }
}
