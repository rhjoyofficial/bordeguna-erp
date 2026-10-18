<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\SetOffs;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\SetOffLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyPosition;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Purchases > Bills & payments > Set-offs (decision D-F, 18 Oct 2026): the
 * list, the form that raises one, its printable page, and the approve and
 * reject the approval inbox calls.
 *
 * ⚠ Read by whoever reads both suppliers and customers (the route gate);
 * raised by Owner and Finance (accounting.set_off.manage); approved by the
 * voucher workflow's stage; reversed only through the corrections process.
 */
class SetOffController extends Controller
{
    public function index(Request $request, SetOffActionPolicy $policy): Response
    {
        $user = $request->user();

        $setOffs = SetOff::query()
            ->with(['supplier:id,name', 'customer:id,name', 'createdBy:id,name', 'reversedBy:id,name', 'reversalJournal:id,journal_number', 'journal:id,journal_number', 'lines.bill:id,bill_number', 'lines.invoice:id,invoice_number'])
            ->latest('set_off_on')->latest('id')->limit(100)->get()
            ->map(fn (SetOff $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'date' => $s->set_off_on?->toDateString(),
                'supplier' => $s->supplier?->name,
                'customer' => $s->customer?->name,
                'amount' => $s->money()->format(),
                'status' => $s->isReversed() ? 'reversed' : $s->status,
                'status_label' => $s->statusLabel(),
                'bills' => $s->lines->whereNotNull('supplier_bill_id')->map(fn (SetOffLine $l) => "{$l->bill?->bill_number} ({$l->money()->format()})")->values()->all(),
                'invoices' => $s->lines->whereNotNull('sales_invoice_id')->map(fn (SetOffLine $l) => "{$l->invoice?->invoice_number} ({$l->money()->format()})")->values()->all(),
                'reason' => $s->reason,
                'by' => $s->createdBy?->name,
                'journal' => $s->journal?->journal_number,
                'reversal' => $s->reversalSummary(),
                'actions' => $policy->actionsFor($s, $user)->toArray()['actions'],
            ]);

        // ⚠ Only LINKED pairs can be set off: one firm, both roles.
        $parties = Customer::query()->whereNotNull('supplier_id')->with('alsoSupplier:id,name,code')->orderBy('name')->get()
            ->filter(fn (Customer $c) => $c->alsoSupplier !== null)
            ->map(fn (Customer $c) => [
                'supplier_id' => $c->supplier_id,
                'customer_id' => $c->id,
                'label' => $c->alsoSupplier->name === $c->name ? $c->name : "{$c->alsoSupplier->name} / {$c->name}",
                'position' => PartyPosition::of($c->alsoSupplier, $c)->toArray(),
            ])->values();

        $supplierIds = $parties->pluck('supplier_id')->all();
        $customerIds = $parties->pluck('customer_id')->all();

        return Inertia::render('accounting/set-offs/index', [
            'setOffs' => $setOffs,
            'parties' => $parties,
            'openBills' => SupplierBill::query()->whereIn('supplier_id', $supplierIds)->owingWithOutstanding()->orderBy('bill_date')->get()
                ->map(fn (SupplierBill $b) => ['id' => $b->id, 'party_id' => $b->supplier_id, 'number' => $b->bill_number, 'date' => $b->bill_date?->toDateString(), 'outstanding' => Money::ofMinor((int) $b->outstanding_paisa)->toTaka()])->values(),
            'openInvoices' => SalesInvoice::query()->whereIn('customer_id', $customerIds)->unsettled()->withOutstandingPaisa()->orderBy('invoice_date')->get()
                ->map(fn (SalesInvoice $i) => ['id' => $i->id, 'party_id' => $i->customer_id, 'number' => $i->invoice_number, 'date' => $i->invoice_date?->toDateString(), 'outstanding' => Money::ofMinor((int) $i->outstanding_paisa)->toTaka()])->values(),
            'canRaise' => $user->can('accounting.set_off.manage'),
            'chosenSupplier' => $request->integer('supplier') ?: null,
            'reversalReasons' => Reason::query()->active()->forContext('reversal')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, SetOffs $setOffs): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'set_off_on' => ['required', 'date'],
            'invoices' => ['array'],
            'invoices.*' => ['nullable', 'numeric', 'min:0'],
            'bills' => ['array'],
            'bills.*' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Tenant-scoped finds: another business's supplier is simply not found.
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $customer = Customer::query()->where('supplier_id', $supplier->id)->first()
            ?? throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} is not linked to a customer, so there is nothing to set off."]);

        $setOff = $setOffs->raise(
            $supplier, $customer, $data['set_off_on'],
            array_map('strval', array_filter($data['invoices'] ?? [], fn ($v) => $v !== null && $v !== '')),
            array_map('strval', array_filter($data['bills'] ?? [], fn ($v) => $v !== null && $v !== '')),
            $data['reason'], $request->user(),
        );

        return redirect()->route('accounting.set-offs.index')
            ->with('success', "{$setOff->number} raised for {$setOff->money()->format()}. It posts when it is approved.");
    }

    /** The set-off as a document: printable, and where the inbox's "Open record" lands. */
    public function show(SetOff $setOff): Response
    {
        $setOff->load(['supplier', 'customer', 'createdBy:id,name', 'postedBy:id,name', 'reversedBy:id,name', 'journal:id,journal_number', 'reversalJournal:id,journal_number', 'lines.bill', 'lines.invoice', 'approvalRequest.decisions.decidedBy:id,name']);

        return Inertia::render('accounting/set-offs/show', [
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'setOff' => [
                'id' => $setOff->id,
                'number' => $setOff->number,
                'date' => $setOff->set_off_on?->toDateString(),
                'supplier' => ['id' => $setOff->supplier_id, 'name' => $setOff->supplier?->name, 'code' => $setOff->supplier?->code],
                'customer' => ['id' => $setOff->customer_id, 'name' => $setOff->customer?->name],
                'amount' => $setOff->money()->format(),
                'status' => $setOff->isReversed() ? 'reversed' : $setOff->status,
                'status_label' => $setOff->statusLabel(),
                'reason' => $setOff->reason,
                'raised_by' => $setOff->createdBy?->name,
                'posted_by' => $setOff->postedBy?->name,
                'posted_at' => $setOff->posted_at?->toDateTimeString(),
                'journal' => $setOff->journal?->journal_number,
                'reversal' => $setOff->reversalSummary(),
                'decisions' => $setOff->approvalRequest?->decisions->map(fn ($d) => [
                    'by' => $d->decidedBy?->name,
                    'decision' => $d->decision?->value ?? (string) $d->decision,
                    'note' => $d->note,
                    'at' => $d->decided_at?->toDateTimeString(),
                    'self_approved' => (bool) $d->self_approved,
                ])->values()->all() ?? [],
                'bills' => $setOff->lines->whereNotNull('supplier_bill_id')->map(fn (SetOffLine $l) => [
                    'number' => $l->bill?->bill_number, 'date' => $l->bill?->bill_date?->toDateString(), 'amount' => $l->money()->format(),
                ])->values()->all(),
                'invoices' => $setOff->lines->whereNotNull('sales_invoice_id')->map(fn (SetOffLine $l) => [
                    'number' => $l->invoice?->invoice_number, 'date' => $l->invoice?->invoice_date?->toDateString(), 'amount' => $l->money()->format(),
                ])->values()->all(),
            ],
        ]);
    }

    public function approve(Request $request, SetOff $setOff, SetOffs $setOffs): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $final = $setOffs->approve($setOff, $request->user(), $note);
        } catch (ValidationException $e) {
            // ⚠ Rolled back: still waiting. The inbox shows no field errors, so
            // the refusal travels as the page message.
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $final ? "{$setOff->number} approved and posted." : 'Approved. Sent to the next stage.');
    }

    public function reject(Request $request, SetOff $setOff, SetOffs $setOffs): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);

        $setOffs->reject($setOff, $request->user(), $data['review_note'] ?? $data['note'] ?? null);

        return back()->with('success', "{$setOff->number} rejected. Nothing was posted.");
    }
}
