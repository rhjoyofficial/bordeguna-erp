<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $invoices = SalesInvoice::query()
            ->with('customer:id,name')
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // ⚠ Outstanding is DERIVED per invoice from its allocations, never
        // stored. Computed here for display only.
        $invoices->getCollection()->transform(function (SalesInvoice $invoice) {
            $invoice->setAttribute('outstanding', $invoice->outstanding()->toTaka());
            $invoice->setAttribute('is_settled', $invoice->isSettled());

            return $invoice;
        });

        $rows = $invoices->getCollection();

        if ($filters['status'] === 'open') {
            $invoices->setCollection($rows->reject->is_settled->values());
        } elseif ($filters['status'] === 'settled') {
            $invoices->setCollection($rows->filter->is_settled->values());
        }

        return Inertia::render('sales/invoices/index', [
            'invoices' => $invoices,
            'filters' => $filters,
        ]);
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load([
            'customer',
            'lines.product:id,name,sku',
            'lines.deliveryLine.delivery:id,delivery_number,delivered_at',
            'allocations.receipt.moneyAccount:id,name',
        ]);

        $journal = Journal::query()
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        return Inertia::render('sales/invoices/show', [
            'invoice' => $invoice,
            'outstanding' => $invoice->outstanding()->toTaka(),
            'received' => $invoice->receivedTotal()->toTaka(),
            'costReleased' => $invoice->costReleased()->toTaka(),
            'isSettled' => $invoice->isSettled(),
            // ⚠ The journal is shown on the invoice on purpose. "Show me what
            // this did to the books" is the question that makes an accountant
            // trust the system, and hiding it behind the ledger screen is how
            // people stop checking.
            'journal' => $journal,
            'moneyAccounts' => MoneyAccount::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Taking money against this invoice.
     */
    public function receipt(Request $request, SalesInvoice $invoice, RecordCustomerReceipt $action): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            customer: $invoice->customer,
            moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
            receivedOn: $data['received_on'],
            allocations: [['invoice' => $invoice, 'amount' => (string) $data['amount']]],
            createdBy: $request->user(),
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Receipt recorded.');
    }
}
