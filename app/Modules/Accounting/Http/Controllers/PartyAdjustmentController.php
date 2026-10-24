<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Corrections\PartyAdjustmentActionPolicy;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance & Accounts > Adjustments: bad-debt write-offs, discounts and
 * set-offs, each against named invoices and bills (finance decision C3).
 */
class PartyAdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        $policy = app(PartyAdjustmentActionPolicy::class);
        $user = $request->user();
        $setOffs = SetOff::query()->whereNotNull('party_adjustment_id')->pluck('number', 'party_adjustment_id');
        // ⭐ 18 Oct 2026 (ruling P3): a supplier discount's allocation, undone by reversing the discount.
        $discounts = SupplierDiscount::query()->whereNotNull('party_adjustment_id')->get(['id', 'number', 'party_adjustment_id', 'supplier_bill_id'])->keyBy('party_adjustment_id');

        return Inertia::render('accounting/adjustments/index', [
            // ⚠⚠ 18 Oct 2026 (D-F): a set-off is raised under Set-offs, numbered and
            // approved, never posted straight from here.
            'kinds' => collect(PartyAdjustment::KINDS)->except(PartyAdjustment::OWNED_BY_DOCUMENTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'adjustments' => PartyAdjustment::query()->with(['customer:id,name', 'supplier:id,name', 'createdBy:id,name', 'reversedBy:id,name', 'allocations.invoice:id,invoice_number', 'allocations.bill:id,bill_number'])
                ->latest('adjusted_on')->latest('id')->limit(100)->get()
                ->map(fn (PartyAdjustment $a) => [
                    'id' => $a->id,
                    'kind' => $a->label(),
                    'date' => $a->adjusted_on?->toDateString(),
                    'party' => trim(($a->customer?->name ?? '').($a->customer && $a->supplier ? ' / ' : '').($a->supplier?->name ?? '')),
                    'amount' => $a->money()->format(),
                    // A reversed adjustment keeps what it had settled (corrections wave 2).
                    'against' => $a->isReversed()
                        ? collect($a->reversed_allocations ?? [])->pluck('number')->filter()->implode(', ')
                        : $a->allocations->map(fn ($al) => $al->invoice?->invoice_number ?? $al->bill?->bill_number)->filter()->implode(', '),
                    'number' => $policy->numberOf($a),
                    'reversal' => $a->reversalSummary(),
                    'allow_today' => ! AccountingPeriod::isClosedOn($a->adjusted_on->copy()->startOfDay()),
                    'corrections' => CorrectionScreen::for($a, $policy, $user),
                    'reason' => $a->reason,
                    'by' => $a->createdBy?->name,
                    // The set-off it belongs to: undone by reversing that, never here.
                    'set_off' => $setOffs[$a->id] ?? null,
                    'discount' => isset($discounts[$a->id]) ? ['number' => $discounts[$a->id]->number, 'url' => route('purchasing.bills.show', $discounts[$a->id]->supplier_bill_id)] : null,
                ]),
            'customers' => Customer::query()->listable()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name]),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name]),
            // ⚠ Bug hunt 16 Oct 2026: every supplier bill ever, and three SUM
            // queries for each bill and invoice. What each owes now comes back
            // on the same row (SAL-009, PUR-008).
            'openInvoices' => SalesInvoice::query()->unsettled()->withOutstandingPaisa()->orderBy('invoice_date')->get()
                ->map(fn (SalesInvoice $i) => ['id' => $i->id, 'party_id' => $i->customer_id, 'number' => $i->invoice_number, 'date' => $i->invoice_date?->toDateString(), 'outstanding' => Money::ofMinor((int) $i->outstanding_paisa)->toTaka()]),
            'openBills' => SupplierBill::query()->owingWithOutstanding()->orderBy('bill_date')->get()
                ->map(fn (SupplierBill $b) => ['id' => $b->id, 'party_id' => $b->supplier_id, 'number' => $b->bill_number, 'date' => $b->bill_date?->toDateString(), 'outstanding' => Money::ofMinor((int) $b->outstanding_paisa)->toTaka()])
                ->values(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordPartyAdjustment $record): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PartyAdjustment::KINDS)), Rule::notIn(PartyAdjustment::OWNED_BY_DOCUMENTS)],
            'adjusted_on' => ['required', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'invoices' => ['array'],
            'invoices.*' => ['nullable', 'numeric', 'min:0'],
            'bills' => ['array'],
            'bills.*' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Tenant-scoped finds: another business's party is simply not found.
        $customer = empty($data['customer_id']) ? null : Customer::query()->findOrFail($data['customer_id']);
        $supplier = empty($data['supplier_id']) ? null : Supplier::query()->findOrFail($data['supplier_id']);

        $record->handle(
            $data['kind'], $data['adjusted_on'], $customer, $supplier,
            array_map('strval', array_filter($data['invoices'] ?? [], fn ($v) => $v !== null && $v !== '')),
            array_map('strval', array_filter($data['bills'] ?? [], fn ($v) => $v !== null && $v !== '')),
            $data['reason'], $request->user(),
        );

        return back()->with('success', 'Adjustment posted.');
    }

    public function destroy(Request $request, PartyAdjustment $adjustment, RecordPartyAdjustment $record): RedirectResponse
    {
        $record->remove($adjustment, $request->user());

        return back()->with('success', 'Adjustment removed and its journal reversed.');
    }
}
