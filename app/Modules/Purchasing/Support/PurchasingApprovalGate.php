<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\RecordSupplierRefund;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Http\Controllers\SupplierAdvanceController;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who has to look at a purchasing document before it posts (ruling P7, Joy's
 * decision 18 Oct 2026).
 *
 * - AT OR ABOVE the Finance policy limit (Tk 200,000) a bill, payment, advance
 *   or refund needs a second person. Below it, nothing changes.
 * - Exceptions need one whatever the amount, each switchable in Finance
 *   policy (non-PO bill and new supplier start OFF, Joy 18 Oct 2026): a non-PO bill, a bill price above the order beyond the tolerance,
 *   an unusual discount, an advance over-application, a new supplier's first
 *   bill or payment, the first payment after a bank or wallet change.
 * - Approvers: the policy's roles (Owner, Manager), NEVER the maker.
 *
 * ⚠⚠ ENFORCED IN THE DOMAIN ACTIONS, not the screens: RecordSupplierBill,
 * RecordSupplierPayment, RecordSupplierAdvance::pay and RefundSupplierAdvance
 * call {@see enforce()} with a {@see PurchaseDraft} before they post, so any
 * caller (a voucher, an import, a future "Buy and receive") is gated. A caller
 * that wants to save the document as pending catches PurchaseApprovalRequired
 * OUTSIDE its transaction and passes it to {@see hold()}. A caller that wants
 * to know first asks {@see assess()}.
 *
 * ⚠ On approval the SAME action runs again from the saved payload, inside
 * {@see approve()}'s transaction with the gate opened for that one approval,
 * so every other rule is checked again as of the approval.
 */
class PurchasingApprovalGate
{
    /** The approval being released, while its document is replayed. */
    private static ?int $releasing = null;

    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /** ⚠ Off only in the test suite (phpunit.xml); see config/accounting.php. */
    public static function enabled(): bool
    {
        return (bool) config('accounting.purchasing_approvals', true);
    }

    /**
     * Why this document needs a second person; empty when it does not.
     *
     * @return list<array{code: string, label: string}>
     */
    public function assess(PurchaseDraft $draft): array
    {
        $policy = FinancePolicy::for((int) $draft->supplier->business_id);
        $reasons = [];

        $limit = (int) $policy->purchasing_approval_at_or_above_minor;

        if ($limit > 0 && $draft->amount->minor >= $limit) {
            $reasons[] = ['code' => 'threshold', 'label' => $draft->amount->format().' is at or above the approval limit of '.Money::ofMinor($limit)->format()];
        }

        if ($policy->purchasing_approve_non_po_bill && $draft->kind === 'bill' && $draft->nonPoLines > 0) {
            $reasons[] = ['code' => 'non_po', 'label' => "{$draft->nonPoLines} line(s) are not on a purchase order"];
        }

        if ($policy->purchasing_approve_price_mismatch && $draft->kind === 'bill') {
            $tolerance = (int) $policy->purchasing_price_tolerance_percent;

            foreach ($draft->priceLines as $line) {
                // Integer paisa: billed x 100 above ordered x (100 + tolerance).
                if ($line['ordered']->minor > 0 && $line['billed']->minor * 100 > $line['ordered']->minor * (100 + $tolerance)) {
                    $reasons[] = ['code' => 'price_mismatch', 'label' => "{$line['label']}: billed {$line['billed']->format()}, the order price is {$line['ordered']->format()} (more than {$tolerance}% above)"];
                }
            }
        }

        if ($policy->purchasing_approve_unusual_discount && $draft->discount !== null && $draft->discount->minor > 0) {
            $percent = (int) $policy->purchasing_unusual_discount_percent;
            $base = $draft->discountBase?->minor ?? 0;

            if ($base <= 0 || $draft->discount->minor * 100 > $base * $percent) {
                $reasons[] = ['code' => 'unusual_discount', 'label' => "a discount of {$draft->discount->format()} is more than {$percent}% of ".Money::ofMinor(max(0, $base))->format()];
            }
        }

        if ($policy->purchasing_approve_advance_over_application && $draft->overApplication !== null) {
            $reasons[] = ['code' => 'advance_over_application', 'label' => $draft->overApplication];
        }

        if ($policy->purchasing_approve_new_supplier && $this->isFirst($draft)) {
            $reasons[] = ['code' => 'new_supplier', 'label' => $draft->kind === 'bill'
                ? "this is the first bill from {$draft->supplier->name}"
                : "this is the first payment to {$draft->supplier->name}"];
        }

        if ($policy->purchasing_approve_bank_change && $this->bankChangedSinceLastPayment($draft)) {
            $reasons[] = ['code' => 'bank_change', 'label' => "{$draft->supplier->name}'s bank or wallet details changed since the last payment"];
        }

        return $reasons;
    }

    /**
     * Refuses to let the document post when it needs a second person.
     *
     * @throws PurchaseApprovalRequired
     */
    public function enforce(PurchaseDraft $draft, User $by): void
    {
        if (! self::enabled() || self::$releasing !== null) {
            return;
        }

        $reasons = $this->assess($draft);

        if ($reasons !== []) {
            throw PurchaseApprovalRequired::for($draft, $reasons);
        }
    }

    /**
     * A supplier payment made by posting a voucher. The voucher has no pending
     * state of its own here, so the second person is whoever posts it: an
     * approver who is not the voucher's preparer (the voucher's own approval
     * makes that the approver).
     *
     * @throws ValidationException
     */
    public function enforceForVoucher(PurchaseDraft $draft, User $poster, int $preparerId): void
    {
        if (! self::enabled() || self::$releasing !== null) {
            return;
        }

        $reasons = $this->assess($draft);

        if ($reasons === [] || ($poster->id !== $preparerId && $this->isApprover($poster, (int) $draft->supplier->business_id))) {
            return;
        }

        $roles = implode(' or ', FinancePolicy::for((int) $draft->supplier->business_id)->purchasingApproverRoles());

        // ⭐ 20 Oct 2026 (party ledgers): a Receipt voucher may also make a
        // supplier refund or an advance refund, each named for what it is.
        [$what, $where] = match ($draft->kind) {
            'supplier_refund' => ["The refund from {$draft->supplier->name}", 'Purchases > Advances, Refund'],
            'refund' => ["The advance refund from {$draft->supplier->name}", 'Purchases > Advances, Refund'],
            default => ["The payment to {$draft->supplier->name}", 'Purchases > Payments'],
        };

        throw ValidationException::withMessages([
            'voucher' => "{$what} needs a second person's approval (".implode('; ', array_column($reasons, 'label'))."). Record it under {$where} so it goes for approval, or have an {$roles} other than the preparer post this voucher.",
        ]);
    }

    /** May this person give the purchasing second approval at all (role only, not the maker rule)? */
    public function isApprover(User $user, int $businessId): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        foreach (FinancePolicy::for($businessId)->purchasingApproverRoles() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves the refused document as pending and tells the approvers.
     *
     * ⚠ Call it OUTSIDE the transaction the action ran in, or the rollback
     * that undid the document undoes the pending row too.
     *
     * @throws ValidationException
     */
    public function hold(PurchaseApprovalRequired $required, User $by): PurchaseApproval
    {
        $draft = $required->draft;
        $businessId = (int) $draft->supplier->business_id;

        if ($draft->kind === 'bill' && $draft->reference !== null) {
            $waiting = PurchaseApproval::query()->where('supplier_id', $draft->supplier->id)
                ->where('kind', 'bill')->where('reference', $draft->reference)->where('status', PurchaseApproval::PENDING)->first();

            if ($waiting !== null) {
                throw ValidationException::withMessages(['bill_number' => "Bill {$draft->reference} from {$draft->supplier->name} is already waiting for approval as {$waiting->number()}."]);
            }
        }

        $this->syncWorkflow($businessId);

        $approval = DB::transaction(function () use ($draft, $required, $by, $businessId) {
            $approval = PurchaseApproval::create([
                'business_id' => $businessId,
                'kind' => $draft->kind,
                'supplier_id' => $draft->supplier->id,
                'amount_minor' => $draft->amount->minor,
                'document_date' => $draft->documentDate->toDateString(),
                'reference' => $draft->reference,
                'summary' => $draft->summary,
                'reasons' => $required->reasons,
                'payload' => $draft->payload,
                'status' => PurchaseApproval::PENDING,
                'created_by' => $by->id,
            ]);

            $approval->submitForApproval($by);

            activity('purchase_approval')->performedOn($approval)->causedBy($by)
                ->withProperties(['reasons' => $required->reasons])
                ->log("Sent {$approval->number()} for approval: {$approval->summary}");

            return $approval;
        });

        $request = $approval->approvalRequest()->with('workflow.stages')->first();

        if ($request !== null) {
            $this->notify->handle($request, "{$approval->kindLabel()} of {$approval->money()->format()} to {$draft->supplier->name} is waiting for your approval");
        }

        return $approval;
    }

    /**
     * Why this person may not decide it, or null when they may.
     */
    public function refusalFor(PurchaseApproval $approval, User $user): ?string
    {
        if (! $approval->isPending()) {
            return "{$approval->number()} is no longer waiting for approval.";
        }

        if ($user->id === (int) $approval->created_by) {
            return 'You entered this, so somebody else has to approve it.';
        }

        if (! $this->isApprover($user, (int) $approval->business_id)) {
            return 'Only '.implode(' or ', FinancePolicy::for((int) $approval->business_id)->purchasingApproverRoles()).' may approve purchasing documents.';
        }

        $request = $approval->approvalRequest;

        if ($request === null || ! $request->canBeActionedBy($user)) {
            return 'You cannot approve this one.';
        }

        return null;
    }

    /**
     * Approves it and posts the document, in one transaction: if the document
     * can no longer post (the bill was invoiced by someone else, the month
     * closed), nothing is approved either.
     *
     * @throws ValidationException
     */
    public function approve(PurchaseApproval $approval, User $by, ?string $note = null): Model
    {
        if (($refusal = $this->refusalFor($approval, $by)) !== null) {
            throw ValidationException::withMessages(['approval' => $refusal]);
        }

        return DB::transaction(function () use ($approval, $by, $note) {
            $approval = PurchaseApproval::query()->lockForUpdate()->findOrFail($approval->id);

            if (! $approval->isPending()) {
                throw ValidationException::withMessages(['approval' => "{$approval->number()} is no longer waiting for approval."]);
            }

            $approval->approvalRequest->approve($by, $note);

            self::$releasing = $approval->id;

            try {
                $document = $this->replay($approval);
            } finally {
                self::$releasing = null;
            }

            $approval->forceFill([
                'status' => PurchaseApproval::APPROVED,
                'document_type' => $document->getMorphClass(),
                'document_id' => $document->getKey(),
                'decision_note' => $note,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ])->save();

            activity('purchase_approval')->performedOn($approval)->causedBy($by)
                ->withProperties(['note' => $note, 'document' => $document->getMorphClass().'#'.$document->getKey()])
                ->log("Approved {$approval->number()} and posted it");

            return $document;
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(PurchaseApproval $approval, User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['review_note' => 'Say why it is rejected.']);
        }

        if (($refusal = $this->refusalFor($approval, $by)) !== null) {
            throw ValidationException::withMessages(['approval' => $refusal]);
        }

        DB::transaction(function () use ($approval, $by, $reason) {
            $approval = PurchaseApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $approval->approvalRequest->reject($by, $reason);
            $approval->forceFill([
                'status' => PurchaseApproval::REJECTED,
                'decision_note' => $reason,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ])->save();

            activity('purchase_approval')->performedOn($approval)->causedBy($by)
                ->withProperties(['reason' => $reason])
                ->log("Rejected {$approval->number()}: nothing was posted");
        });
    }

    /**
     * The approvers follow Finance policy: the workflow stage carries the
     * policy's roles, so the inbox and the emails reach the right people.
     */
    public function syncWorkflow(int $businessId): void
    {
        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->firstOrCreate(
            ['business_id' => $businessId, 'key' => 'purchase_approval'],
            ['name' => 'Purchasing approval (bills and payments)', 'is_active' => true],
        );

        $roles = FinancePolicy::for($businessId)->purchasingApproverRoles();
        $stage = $workflow->stages()->orderBy('sequence')->first();

        if ($stage === null) {
            $workflow->stages()->create(['sequence' => 1, 'name' => 'Second person', 'allowed_roles' => $roles, 'allowed_permissions' => []]);
        } elseif ($stage->allowed_roles !== $roles) {
            $stage->update(['allowed_roles' => $roles]);
        }
    }

    /** The document, created by the same action from what was typed, as its maker. */
    private function replay(PurchaseApproval $approval): Model
    {
        $p = $approval->payload;
        $maker = User::query()->findOrFail($approval->created_by);
        $supplier = Supplier::query()->findOrFail($approval->supplier_id);

        return match ($approval->kind) {
            'bill' => $this->replayBill($p, $supplier, $maker),
            'payment' => app(RecordSupplierPayment::class)->handle(
                supplier: $supplier,
                moneyAccount: MoneyAccount::query()->findOrFail($p['money_account_id']),
                paidOn: $p['paid_on'],
                allocations: array_map(fn (array $row) => [
                    'bill' => SupplierBill::query()->findOrFail($row['supplier_bill_id']),
                    'amount' => (string) $row['amount'],
                    'discount' => (string) ($row['discount'] ?? '0'),
                ], $p['allocations']),
                createdBy: $maker,
                reference: $p['reference'] ?? null,
                note: $p['note'] ?? null,
                tdsRate: empty($p['tds_rate_id']) ? null : WithholdingRate::query()->find($p['tds_rate_id']),
                vdsRate: empty($p['vds_rate_id']) ? null : WithholdingRate::query()->find($p['vds_rate_id']),
                excessToAdvance: (bool) ($p['excess_to_advance'] ?? false),
                discountEvidence: $p['discount_evidence'] ?? null,
                discountReason: $p['discount_reason'] ?? null,
            ),
            'advance' => app(RecordSupplierAdvance::class)->pay(
                supplier: $supplier,
                moneyAccount: MoneyAccount::query()->findOrFail($p['money_account_id']),
                paidOn: $p['paid_on'],
                amount: (string) $p['amount'],
                createdBy: $maker,
                purchaseOrder: empty($p['purchase_order_id']) ? null : PurchaseOrder::query()->findOrFail($p['purchase_order_id']),
                reference: $p['reference'] ?? null,
                note: $p['note'] ?? null,
                tdsRate: empty($p['tds_rate_id']) ? null : WithholdingRate::query()->find($p['tds_rate_id']),
                vdsRate: empty($p['vds_rate_id']) ? null : WithholdingRate::query()->find($p['vds_rate_id']),
            ),
            'refund' => app(RefundSupplierAdvance::class)->handle(
                supplier: $supplier,
                advance: empty($p['supplier_advance_id']) ? null : SupplierAdvance::query()->findOrFail($p['supplier_advance_id']),
                moneyAccount: MoneyAccount::query()->findOrFail($p['money_account_id']),
                refundedOn: $p['refunded_on'],
                amount: (string) $p['amount'],
                by: $maker,
                reference: $p['reference'] ?? null,
                note: $p['note'] ?? null,
            ),
            // ⭐ Ruling P5: a supplier paying back a debit on Accounts Payable (SREF).
            'supplier_refund' => app(RecordSupplierRefund::class)->handle(
                supplier: $supplier,
                moneyAccount: MoneyAccount::query()->findOrFail($p['money_account_id']),
                receivedOn: $p['received_on'],
                amount: (string) $p['amount'],
                by: $maker,
                reference: $p['reference'] ?? null,
                note: $p['note'] ?? null,
            ),
            default => throw ValidationException::withMessages(['approval' => "Unknown purchasing document kind {$approval->kind}."]),
        };
    }

    /** @param  array<string, mixed>  $p */
    private function replayBill(array $p, Supplier $supplier, User $maker): SupplierBill
    {
        $bill = app(RecordSupplierBill::class)->handle(
            supplier: $supplier,
            billNumber: (string) $p['bill_number'],
            billDate: $p['bill_date'],
            lines: array_map(fn (array $line) => array_filter([
                'line' => PurchaseOrderLine::query()->findOrFail($line['purchase_order_line_id']),
                // Ruling P5: the delivery the line bills.
                'receiptLine' => empty($line['goods_receipt_line_id']) ? null : GoodsReceiptLine::query()->findOrFail($line['goods_receipt_line_id']),
                'quantity' => (string) $line['quantity'],
                'unitCost' => $line['unitCost'] ?? null,
                'lineTotal' => $line['lineTotal'] ?? null,
                'entryMode' => $line['entryMode'] ?? null,
                'taxRate' => $line['taxRate'] ?? null,
                'taxRecoverable' => $line['taxRecoverable'] ?? null,
            ], fn ($value) => $value !== null), $p['lines']),
            createdBy: $maker,
            purchaseOrder: empty($p['purchase_order_id']) ? null : PurchaseOrder::query()->findOrFail($p['purchase_order_id']),
            dueDate: $p['due_date'] ?? null,
            note: $p['note'] ?? null,
            pricesIncludeTax: (bool) ($p['prices_include_tax'] ?? false),
            tradeDiscount: $p['trade_discount'] ?? null,
            terms: PaymentTerms::of($p['terms']['type'] ?? null, $p['terms']['days'] ?? null),
            dueDateReason: $p['due_date_reason'] ?? null,
            // Ruling P5: service lines, carriage and the duplicate answer the
            // maker gave; the duplicate question is not asked again.
            expenseLines: array_map(fn (array $row) => [
                'account' => Account::query()->findOrFail($row['expense_account_id']),
                'description' => $row['description'],
                'amount' => (string) $row['amount'],
            ], $p['services'] ?? []),
            carriageLines: $p['carriage'] ?? [],
            duplicateReason: $p['duplicate_reason'] ?? null,
        );

        // The advances the maker chose to apply as the bill was saved.
        $advances = $p['follow_ups']['advances'] ?? [];

        if ($advances !== []) {
            app(ApplySupplierAdvances::class)->handle($bill, SupplierAdvanceController::resolveRows($advances), $p['bill_date'], $maker);
        }

        return $bill->fresh('lines');
    }

    /** No live bill (for a bill), or no live payment or advance (for a payment), before this one. */
    private function isFirst(PurchaseDraft $draft): bool
    {
        $supplierId = $draft->supplier->id;

        return match ($draft->kind) {
            'bill' => ! SupplierBill::query()->where('supplier_id', $supplierId)->whereNull('reversed_at')
                ->when($draft->inProgressId !== null, fn ($query) => $query->whereKeyNot($draft->inProgressId))->exists(),
            'payment', 'advance' => ! SupplierPayment::query()->where('supplier_id', $supplierId)->live()->exists()
                && ! SupplierAdvance::query()->where('supplier_id', $supplierId)->where('kind', '!=', 'opening')->live()->exists(),
            default => false,
        };
    }

    /**
     * Money going to a supplier's bank or wallet after its details changed,
     * with nothing paid to it since. Cash in hand goes to no account.
     */
    private function bankChangedSinceLastPayment(PurchaseDraft $draft): bool
    {
        if (! in_array($draft->kind, ['payment', 'advance'], true) || $draft->moneyAccount === null) {
            return false;
        }

        if (! in_array($draft->moneyAccount->type, [MoneyAccountType::Bank, MoneyAccountType::Mfs], true)) {
            return false;
        }

        $changedAt = $draft->supplier->bank_details_changed_at;

        if ($changedAt === null) {
            return false;
        }

        $supplierId = $draft->supplier->id;

        return ! SupplierPayment::query()->where('supplier_id', $supplierId)->live()->where('created_at', '>=', $changedAt)->exists()
            && ! SupplierAdvance::query()->where('supplier_id', $supplierId)->where('kind', 'payment')->live()->where('created_at', '>=', $changedAt)->exists();
    }
}
