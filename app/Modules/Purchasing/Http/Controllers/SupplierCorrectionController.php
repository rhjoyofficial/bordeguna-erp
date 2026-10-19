<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Actions\FixSupplierBillDetails;
use App\Modules\Purchasing\Corrections\SupplierBillActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierPaymentActionPolicy;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): the Correct forms of the supplier
 * money documents, and a bill's Fix details.
 *
 * Nothing is SAVED from a Correct form: "Preview and correct" opens the action
 * dialog, which previews the date, the effect and what stays, then posts to
 * the generic /records/{type}/{id}/correct route (the corrections process).
 * These pages only open the record's own form, pre-filled.
 *
 * ⚠ The policy decides; a page that opens for a record that cannot be
 * corrected shows why, greyed, and its button cannot submit.
 */
class SupplierCorrectionController extends Controller
{
    use ValidatesActionReason;

    /** Bill: Fix details (due date, note, the supplier's number). No journal moves. */
    public function fixBillDetails(Request $request, SupplierBill $bill, FixSupplierBillDetails $action): RedirectResponse
    {
        abort_unless($request->user()->canAny(SupplierBillActionPolicy::FIX_PERMISSIONS), 403);

        $data = $request->validate([
            ...$this->actionReasonRules('fix_details'),
            'bill_number' => ['required', 'string', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'seen' => ['required', 'string', 'size:64'],
        ], $this->actionReasonMessages());

        $fixed = $action->handle(
            $bill,
            ['bill_number' => $data['bill_number'], 'due_date' => $data['due_date'] ?? null, 'note' => $data['note'] ?? null],
            $this->actionReason($request),
            $request->user(),
            $data['seen'],
        );

        return back()->with('success', "Bill {$fixed->bill_number}: details fixed. The change and your reason are in its history.");
    }

    public function correctBill(Request $request, SupplierBill $bill, SupplierBillActionPolicy $policy): Response|RedirectResponse
    {
        if ($bill->isReversed()) {
            return $this->alreadyDone($bill, $policy, route('purchasing.bills.show', $bill));
        }

        $bill->load(['supplier:id,name', 'lines.purchaseOrderLine.product:id,name,sku', 'lines.purchaseOrderLine.purchaseOrder:id,po_number', 'lines.goodsReceiptLine.goodsReceipt:id,supplier_challan_number']);

        return Inertia::render('purchasing/bills/correct', [
            'bill' => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                // A due date that came from the terms is left blank, so a new
                // bill date works it out again; a typed one is kept.
                'due_date' => in_array($bill->due_date_source, ['typed', 'override', 'changed'], true) ? $bill->due_date?->toDateString() : '',
                'note' => $bill->note ?? '',
                'supplier' => $bill->supplier?->name,
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                'settled' => $bill->paidTotal()->plus($bill->creditedTotal())->toTaka(),
                'lines' => $bill->lines->reject(fn (SupplierBillLine $line) => $line->isExpense())->map(fn (SupplierBillLine $line) => [
                    'purchase_order_line_id' => (int) $line->purchase_order_line_id,
                    'goods_receipt_line_id' => $line->goods_receipt_line_id === null ? null : (int) $line->goods_receipt_line_id,
                    'product' => $line->purchaseOrderLine?->product?->name,
                    'po_number' => $line->purchaseOrderLine?->purchaseOrder?->po_number,
                    'challan' => $line->goodsReceiptLine?->goodsReceipt?->supplier_challan_number,
                    'quantity' => $this->quantity((string) $line->quantity),
                    'unit_cost' => rtrim(rtrim((string) $line->unit_cost, '0'), '.'),
                    'line_total' => Money::ofTaka((string) $line->line_total)->toTaka(),
                    'entry_mode' => $line->entry_mode?->value ?? 'rate',
                    // What arrived and is not billed elsewhere, this bill's own part included.
                    'max_quantity' => $this->quantity(number_format(max(0, PurchaseLineEntry::scaled((string) $line->purchaseOrderLine?->quantity_received)
                        - PurchaseLineEntry::scaled((string) $line->purchaseOrderLine?->quantity_returned)
                        - PurchaseLineEntry::scaled((string) $line->purchaseOrderLine?->quantity_invoiced)
                        + PurchaseLineEntry::scaled((string) $line->quantity)) / 10000, 4, '.', '')),
                ])->values(),
                'services' => $bill->lines->filter(fn (SupplierBillLine $line) => $line->isExpense())->map(fn (SupplierBillLine $line) => [
                    'expense_account_id' => (string) $line->expense_account_id,
                    'description' => $line->description ?? '',
                    'amount' => Money::ofTaka((string) $line->line_total)->toTaka(),
                ])->values(),
            ],
            'expense_accounts' => Account::query()
                ->where('type', AccountType::Expense)->where('is_postable', true)->where('is_active', true)
                ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])->all(),
            ...$this->screen($bill, $policy, $request),
        ]);
    }

    public function correctPayment(Request $request, SupplierPayment $payment, SupplierPaymentActionPolicy $policy): Response|RedirectResponse
    {
        if ($payment->isReversed()) {
            return $this->alreadyDone($payment, $policy, route('purchasing.payments.show', $payment));
        }

        $payment->load(['supplier:id,name', 'allocations']);
        $own = $payment->allocations->groupBy('supplier_bill_id')->map(fn ($rows) => $rows->sum(fn ($a) => Money::ofTaka((string) $a->amount)->minor));

        // The supplier's live bills that owe something, plus the ones this
        // payment paid (they owe again once it is reversed).
        $bills = SupplierBill::query()->where('supplier_id', $payment->supplier_id)->whereNull('reversed_at')
            ->orderBy('bill_date')->orderBy('id')->get(['id', 'bill_number', 'bill_date', 'total', 'reversed_at'])
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'open' => Money::ofMinor($bill->outstanding()->minor + (int) ($own[$bill->id] ?? 0))->toTaka(),
                'amount' => isset($own[$bill->id]) ? Money::ofMinor((int) $own[$bill->id])->toTaka() : '',
            ])
            ->filter(fn (array $row) => (float) $row['open'] > 0)
            ->values();

        return Inertia::render('purchasing/payments/correct', [
            'payment' => [
                'id' => $payment->id,
                'paid_on' => $payment->paid_on?->toDateString(),
                'money_account_id' => (string) $payment->money_account_id,
                'reference' => $payment->reference ?? '',
                'note' => $payment->note ?? '',
                'tds_rate_id' => $payment->tds_rate_id,
                'vds_rate_id' => $payment->vds_rate_id,
                'amount' => Money::ofTaka((string) $payment->amount)->toTaka(),
                'supplier' => $payment->supplier?->name,
            ],
            'bills' => $bills,
            'money_accounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name])->all(),
            ...$this->screen($payment, $policy, $request),
        ]);
    }

    public function correctCreditNote(Request $request, SupplierCreditNote $creditNote, SupplierCreditNoteActionPolicy $policy): Response|RedirectResponse
    {
        if ($creditNote->isReversed()) {
            return $this->alreadyDone($creditNote, $policy, route('purchasing.credit-notes.show', $creditNote));
        }

        $creditNote->load(['supplier:id,name', 'allocations']);
        $own = $creditNote->allocations->groupBy('supplier_bill_id')->map(fn ($rows) => $rows->sum(fn ($a) => Money::ofTaka((string) $a->amount)->minor));

        $bills = SupplierBill::query()->where('supplier_id', $creditNote->supplier_id)->whereNull('reversed_at')
            ->orderBy('bill_date')->orderBy('id')->get(['id', 'bill_number', 'bill_date', 'total', 'reversed_at'])
            ->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'open' => Money::ofMinor($bill->outstanding()->minor + (int) ($own[$bill->id] ?? 0))->toTaka(),
                'amount' => isset($own[$bill->id]) ? Money::ofMinor((int) $own[$bill->id])->toTaka() : '',
            ])
            ->filter(fn (array $row) => (float) $row['open'] > 0)
            ->values();

        return Inertia::render('purchasing/credit-notes/correct', [
            'creditNote' => [
                'id' => $creditNote->id,
                'credit_note_number' => $creditNote->credit_note_number,
                'credit_date' => $creditNote->credit_date?->toDateString(),
                'note' => $creditNote->note ?? '',
                'tax_amount' => Money::ofTaka((string) $creditNote->tax_total)->toTaka(),
                'total' => Money::ofTaka((string) $creditNote->total)->toTaka(),
                'supplier' => $creditNote->supplier?->name,
                'purchase_return_id' => $creditNote->purchase_return_id,
            ],
            'bills' => $bills,
            ...$this->screen($creditNote, $policy, $request),
        ]);
    }

    /** @return array<string, mixed> */
    private function screen(Model $document, $policy, Request $request): array
    {
        $screen = CorrectionScreen::for($document, $policy, $request->user());
        $correct = collect($screen['actions'])->firstWhere('action', DocumentAction::Correct->value);

        return [
            'availability' => $correct,
            'type' => $screen['type'],
            'correctionReasons' => $screen['reasons']['correction'],
            'today' => Carbon::today()->toDateString(),
        ];
    }

    private function alreadyDone(Model $document, $policy, string $back): RedirectResponse
    {
        session()->reflash();
        $blocker = $policy->availability($document, request()->user(), DocumentAction::Correct)->blockers[0] ?? null;

        return redirect($back)->with('error', $blocker?->message ?? "{$policy->numberOf($document)} was already reversed.");
    }

    private function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
