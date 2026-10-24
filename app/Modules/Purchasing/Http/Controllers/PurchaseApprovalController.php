<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchases > Bills & payments > Waiting approval (ruling P7, 18 Oct 2026).
 *
 * Bills, payments, advances and refunds that need a second person: what was
 * typed, why it needs approval, and Approve / Reject for an approver who did
 * not enter it. The same rows reach the common approvals inbox through
 * ApprovalCards, and both buttons call the same PurchasingApprovalGate.
 */
class PurchaseApprovalController extends Controller
{
    public function index(Request $request, PurchasingApprovalGate $gate): Response
    {
        $status = in_array($request->query('status'), [PurchaseApproval::APPROVED, PurchaseApproval::REJECTED, PurchaseApproval::WITHDRAWN, 'all'], true)
            ? $request->query('status')
            : PurchaseApproval::PENDING;

        $approvals = PurchaseApproval::query()
            ->with(['supplier:id,name', 'createdBy:id,name', 'decidedBy:id,name', 'approvalRequest'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(200)
            ->get();

        return Inertia::render('purchasing/approvals/index', [
            'approvals' => $approvals->map(fn (PurchaseApproval $approval) => $this->row($approval, $request, $gate))->values(),
            'status' => $status,
        ]);
    }

    public function show(Request $request, PurchaseApproval $approval, PurchasingApprovalGate $gate): Response
    {
        $approval->load(['supplier:id,name,code', 'createdBy:id,name', 'decidedBy:id,name', 'approvalRequest.decisions']);

        return Inertia::render('purchasing/approvals/show', [
            'approval' => [
                ...$this->row($approval, $request, $gate),
                'details' => $this->details($approval),
                'decision_note' => $approval->decision_note,
            ],
        ]);
    }

    public function approve(Request $request, PurchaseApproval $approval, PurchasingApprovalGate $gate): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $document = $gate->approve($approval, $request->user(), $note);
        } catch (ValidationException $e) {
            // ⚠ Rolled back: still waiting. The inbox shows no field errors, so
            // the refusal travels as the page message.
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return redirect()->to($this->documentUrl($document) ?? route('purchasing.approvals.show', $approval))
            ->with('success', "{$approval->number()} approved and posted.");
    }

    public function reject(Request $request, PurchaseApproval $approval, PurchasingApprovalGate $gate): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);

        try {
            $gate->reject($approval, $request->user(), (string) ($data['review_note'] ?? $data['note'] ?? ''));
        } catch (ValidationException $e) {
            return back()->with('error', 'Not rejected: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', "{$approval->number()} rejected. Nothing was posted.");
    }

    /** @return array<string, mixed> */
    private function row(PurchaseApproval $approval, Request $request, PurchasingApprovalGate $gate): array
    {
        $refusal = $approval->isPending() ? $gate->refusalFor($approval, $request->user()) : null;

        return [
            'id' => $approval->id,
            'number' => $approval->number(),
            'kind' => $approval->kind,
            'kind_label' => $approval->kindLabel(),
            'summary' => $approval->summary,
            'supplier' => $approval->supplier?->name,
            'amount' => $approval->money()->toTaka(),
            'document_date' => $approval->document_date?->toDateString(),
            'reference' => $approval->reference,
            'reasons' => $approval->reasonLabels(),
            'status' => $approval->status,
            'created_by' => $approval->createdBy?->name,
            'created_at' => $approval->created_at?->toDateTimeString(),
            'decided_by' => $approval->decidedBy?->name,
            'decided_at' => $approval->decided_at?->toDateTimeString(),
            'document_url' => $approval->document_id === null ? null : $this->documentUrl($approval->document),
            'can_decide' => $approval->isPending() && $refusal === null,
            // Why the buttons are missing, for the maker and anyone else.
            'cannot_decide' => $approval->isPending() ? $refusal : null,
            'approve_url' => route('purchasing.approvals.approve', $approval),
            'reject_url' => route('purchasing.approvals.reject', $approval),
        ];
    }

    /**
     * What was typed, in words, so the approver sees the document itself.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(PurchaseApproval $approval): array
    {
        $p = $approval->payload;
        $rows = [];

        if ($approval->kind === 'bill') {
            $rows[] = ['label' => 'Supplier bill number', 'value' => (string) ($p['bill_number'] ?? '')];
            $rows[] = ['label' => 'Bill date', 'value' => (string) ($p['bill_date'] ?? '')];

            foreach ($p['lines'] ?? [] as $index => $line) {
                $value = $line['quantity'].' x '.($line['unitCost'] ?? '-').(isset($line['lineTotal']) ? ' (total Tk '.$line['lineTotal'].')' : '');
                $rows[] = ['label' => 'Line '.($index + 1), 'value' => $value];
            }

            if (! empty($p['trade_discount'])) {
                $rows[] = ['label' => 'Trade discount', 'value' => ($p['trade_discount']['mode'] ?? '').' '.($p['trade_discount']['value'] ?? '')];
            }

            // Ruling P5: service lines and carriage on the same bill.
            foreach ($p['services'] ?? [] as $row) {
                $rows[] = ['label' => 'Service', 'value' => "{$row['description']}: Tk {$row['amount']}"];
            }

            foreach ($p['carriage'] ?? [] as $row) {
                $rows[] = ['label' => 'Carriage', 'value' => "{$row['cost_type']}: Tk {$row['amount']} on goods receipt #{$row['goods_receipt_id']}"];
            }

            if (! empty($p['duplicate_reason'])) {
                $rows[] = ['label' => 'Looked like an earlier bill', 'value' => (string) $p['duplicate_reason']];
            }

            if (! empty($p['follow_ups']['advances'])) {
                $rows[] = ['label' => 'Advances to apply', 'value' => collect($p['follow_ups']['advances'])->map(fn ($row) => "{$row['key']}: Tk {$row['amount']}")->implode(', ')];
            }

            // ⭐ 24 Oct 2026: Buy and receive pays this bill once it is approved.
            if (! empty($p['follow_ups']['quick_payment'])) {
                $pay = $p['follow_ups']['quick_payment'];
                $rows[] = ['label' => 'Payment made on approval', 'value' => "Tk {$pay['amount']} from ".($pay['money_account'] ?? 'account #'.$pay['money_account_id'])];
            }
        }

        if ($approval->kind === 'payment') {
            foreach ($p['allocations'] ?? [] as $row) {
                $bill = SupplierBill::query()->find($row['supplier_bill_id']);
                $discount = (float) ($row['discount'] ?? 0) > 0 ? ", discount Tk {$row['discount']}" : '';
                $rows[] = ['label' => 'Bill '.($bill?->bill_number ?? '#'.$row['supplier_bill_id']), 'value' => "Tk {$row['amount']}{$discount}"];
            }
        }

        if (in_array($approval->kind, ['payment', 'advance', 'refund', 'supplier_refund'], true)) {
            $rows[] = ['label' => 'Date', 'value' => (string) ($p['paid_on'] ?? $p['refunded_on'] ?? $p['received_on'] ?? '')];
        }

        if (! empty($p['note'])) {
            $rows[] = ['label' => 'Note', 'value' => (string) $p['note']];
        }

        $rows[] = ['label' => 'Approval amount', 'value' => $approval->money()->format()];

        return $rows;
    }

    private function documentUrl(?Model $document): ?string
    {
        return match (true) {
            $document instanceof SupplierBill => route('purchasing.bills.show', $document),
            $document instanceof SupplierPayment => route('purchasing.payments.show', $document),
            $document instanceof SupplierAdvance => route('purchasing.advances.show', $document),
            $document instanceof SupplierAdvanceRefund => $document->supplier_advance_id ? route('purchasing.advances.show', $document->supplier_advance_id) : route('purchasing.advances.index'),
            $document instanceof SupplierRefund => route('purchasing.advances.supplier', $document->supplier_id),
            default => null,
        };
    }
}
