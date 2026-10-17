<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordOpeningBalanceDocument;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance & Accounts > Opening balances: who owed us and whom we owed at
 * cutover, one row per open invoice or bill (finance decision A4/A5).
 */
class OpeningBalanceController extends Controller
{
    public function index(): Response
    {
        $invoices = SalesInvoice::query()->where('is_opening', true)->with('customer:id,name')
            ->orderBy('invoice_date')->orderBy('id')->get()
            ->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'party' => $invoice->customer?->name,
                'number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date?->toDateString(),
                'due' => $invoice->due_date?->toDateString(),
                'amount' => $invoice->totalMoney()->format(),
                'outstanding' => $invoice->outstanding()->format(),
                'removable' => ! $invoice->allocations()->exists() && ! $invoice->creditAllocations()->exists() && ! $invoice->adjustmentAllocations()->exists(),
            ]);

        $bills = SupplierBill::query()->where('is_opening', true)->with('supplier:id,name')
            ->orderBy('bill_date')->orderBy('id')->get()
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'party' => $bill->supplier?->name,
                'number' => $bill->bill_number,
                'date' => $bill->bill_date?->toDateString(),
                'due' => $bill->due_date?->toDateString(),
                'amount' => Money::ofTaka((string) $bill->total)->format(),
                'outstanding' => $bill->outstanding()->format(),
                'removable' => ! $bill->allocations()->exists() && ! $bill->creditAllocations()->exists() && ! $bill->adjustmentAllocations()->exists(),
            ]);

        $sum = fn ($query) => Money::ofTaka((string) ($query->sum('total') ?: '0'))->format();

        return Inertia::render('accounting/opening-balances/index', [
            'invoices' => $invoices,
            'bills' => $bills,
            'totals' => [
                'receivable' => $sum(SalesInvoice::query()->where('is_opening', true)),
                'payable' => $sum(SupplierBill::query()->where('is_opening', true)),
            ],
            'customers' => Customer::query()->listable()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name]),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name]),
        ]);
    }

    public function store(Request $request, RecordOpeningBalanceDocument $record): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['receivable', 'payable'])],
            'party_id' => ['required', 'integer'],
            'reference' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
        ]);

        if ($data['kind'] === 'receivable') {
            // Tenant-scoped: another business's customer is simply not found.
            $customer = Customer::query()->findOrFail($data['party_id']);
            $record->invoice($customer, $data['reference'], $data['date'], $data['due_date'] ?? null, (string) $data['amount'], $request->user());
        } else {
            $supplier = Supplier::query()->findOrFail($data['party_id']);
            $record->bill($supplier, $data['reference'], $data['date'], $data['due_date'] ?? null, (string) $data['amount'], $request->user());
        }

        return back()->with('success', 'Opening balance saved.');
    }

    public function destroy(Request $request, string $kind, int $id, RecordOpeningBalanceDocument $record): RedirectResponse
    {
        $document = $kind === 'receivable'
            ? SalesInvoice::query()->where('is_opening', true)->findOrFail($id)
            : SupplierBill::query()->where('is_opening', true)->findOrFail($id);

        $record->remove($document, $request->user());

        return back()->with('success', 'Opening balance removed.');
    }
}
