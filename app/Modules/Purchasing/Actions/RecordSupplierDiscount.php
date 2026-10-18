<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a supplier's trade or settlement discount (ChatGPT ruling P3,
 * 18 Oct 2026) and posts it as a reduction of what the goods cost.
 *
 *   Dr  Accounts Payable (party = supplier)       the discount: the bill owes less
 *       Cr  Stock on Hand                         the on-hand share, per batch, each
 *                                                 with its value-only movement (cost down)
 *       Cr  purchasing.discount_consumed          the share of units already consumed or sold
 *
 * ⚠⚠ NEVER 4950 AND NEVER 5400. IAS 2 / IFRS for SMEs 13.6 and the IFRIC
 * cash-discount decision: a discount on goods is a deduction from their cost.
 * 4950 stays for genuine non-price items (the Owner-only party adjustment
 * `supplier_discount`), and PPV stays for a plain price gap between the order
 * and the bill. A discount whose goods cannot be identified is REFUSED with
 * the reason (PlanSupplierDiscount), never parked on either.
 *
 * ⚠ The bill's outstanding falls through a party adjustment of the matching
 * kind, created here with its allocation, so the payment form, the ageing,
 * the cash forecast, the cockpit and the subledger reconciliation see the
 * discount without being taught a new table. The journal's source is the
 * DISCOUNT, so the corrections process finds exactly one ledger entry on it.
 *
 * ⚠ Controls (ruling P3, "Discount controls"): a reference to the supplier's
 * document or evidence, always; a settlement discount also needs a reason and
 * the permission purchasing.discount.settle; the same reference twice for one
 * supplier, or a discount on a bill that already carries a 4950 supplier
 * discount adjustment, is double counting and refused.
 */
class RecordSupplierDiscount
{
    public function __construct(
        private readonly PlanSupplierDiscount $planner,
        private readonly RevalueStockBatch $revalue,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * The discount printed on the supplier's bill, recorded with the bill.
     *
     * @param  array<int, int>|null  $perLine  supplier_bill_line_id => paisa, when typed per line
     *
     * @throws ValidationException
     */
    public function trade(SupplierBill $bill, Money $amount, string $reference, User $by, string $basis = 'amount', ?string $percent = null, ?array $perLine = null, ?string $reason = null): SupplierDiscount
    {
        return $this->record(SupplierDiscount::TRADE, $bill, $amount, Carbon::parse($bill->bill_date)->startOfDay(), $reference, $reason, $by, null, $basis, $percent, $perLine, 'discount');
    }

    /**
     * A prompt-payment discount taken while paying: dated the payment date.
     *
     * @throws ValidationException
     */
    public function settlement(SupplierBill $bill, Money $amount, SupplierPayment $payment, string $evidence, string $reason, User $by, string $field = 'discount'): SupplierDiscount
    {
        if (! $by->can('purchasing.discount.settle')) {
            throw ValidationException::withMessages([$field => 'Taking a settlement discount needs the permission to settle discounts (Owner or Finance). Pay what is owed, or leave the rest of the bill open.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([$field => 'Say why the supplier allowed this discount: the reason is part of the record.']);
        }

        return $this->record(SupplierDiscount::SETTLEMENT, $bill, $amount, Carbon::parse($payment->paid_on)->startOfDay(), $evidence, $reason, $by, $payment, 'amount', null, null, $field);
    }

    /**
     * @param  array<int, int>|null  $perLine
     *
     * @throws ValidationException
     */
    private function record(string $kind, SupplierBill $bill, Money $amount, Carbon $on, string $reference, ?string $reason, User $by, ?SupplierPayment $payment, string $basis, ?string $percent, ?array $perLine, string $field): SupplierDiscount
    {
        $reference = trim($reference);
        $refuse = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($amount->minor <= 0) {
            $refuse('A discount has to be more than zero.');
        }

        if ($reference === '') {
            $refuse($kind === SupplierDiscount::TRADE
                ? "Give the supplier's document number the discount is printed on."
                : "Give the evidence of the supplier's agreement (their letter, message or document number).");
        }

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null || AccountMapping::forKeyOn('purchasing.discount_consumed', $on) === null) {
            $refuse('Discounts are posted to stock and cost of sales, and inventory posting is not switched on for this business (or purchasing.discount_consumed is unmapped).');
        }

        AccountingPeriod::assertOpenFor($on);

        return DB::transaction(function () use ($kind, $bill, $amount, $on, $reference, $reason, $by, $payment, $basis, $percent, $perLine, $refuse) {
            // ⚠ Re-read under a lock: two discounts on one bill at once must
            // not both pass the outstanding cap.
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($bill->isReversed() || $bill->is_opening || $bill->isLandedCostBill()) {
                $refuse("Bill {$bill->bill_number} has no goods of its own to discount (reversed, an opening balance or a landed cost's payable).");
            }

            if ($amount->minor > $bill->outstanding()->minor) {
                $refuse("Bill {$bill->bill_number} has only {$bill->outstanding()->format()} outstanding.");
            }

            $this->assertNotCountedTwice($bill, $reference, $refuse);

            $plan = $this->planner->handle($bill, $amount->minor, $on, $perLine);

            if ($plan['refusals'] !== []) {
                $refuse(implode(' ', $plan['refusals']));
            }

            $business = Business::withoutGlobalScopes()->findOrFail($bill->business_id);
            $prefix = 'SD-'.FiscalYear::containing($on, $business)->label().'-';

            $discount = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(SupplierDiscount::query(), 'number', $prefix),
                fn (string $number) => SupplierDiscount::create([
                    'business_id' => $bill->business_id,
                    'number' => $number,
                    'kind' => $kind,
                    'supplier_id' => $bill->supplier_id,
                    'supplier_bill_id' => $bill->id,
                    'supplier_payment_id' => $payment?->id,
                    'discount_on' => $on->toDateString(),
                    'amount' => $amount->toTaka(),
                    'basis' => $basis,
                    'percent' => $percent,
                    'reference' => $reference,
                    'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                    'on_hand_paisa' => $plan['on_hand'],
                    'consumed_paisa' => $plan['consumed'],
                    'created_by' => $by->id,
                ]),
            );

            // What lowers the bill's outstanding, read by every adjustment reader.
            $adjustment = PartyAdjustment::create([
                'business_id' => $bill->business_id,
                'kind' => SupplierDiscount::ADJUSTMENT_KINDS[$kind],
                'adjusted_on' => $on,
                'customer_id' => null,
                'supplier_id' => $bill->supplier_id,
                'amount' => $amount->toTaka(),
                'reason' => "{$discount->label()} {$discount->number} ({$reference})",
                'created_by' => $by->id,
            ]);
            $adjustment->allocations()->create(['business_id' => $bill->business_id, 'supplier_bill_id' => $bill->id, 'amount' => $amount->toTaka()]);

            $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
            $consumedHead = AccountMapping::resolve('purchasing.discount_consumed', $on);
            $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);
            $memo = "{$discount->label()} {$discount->number} on bill {$bill->bill_number}";

            $lines = [JournalLineDraft::debit($payable->id, $amount, $memo, $bill->supplier)];

            foreach ($plan['allocations'] as $allocation) {
                $batch = StockBatch::query()->whereKey($allocation['batch_id'])->lockForUpdate()->firstOrFail();
                $onHandShare = Money::ofMinor($allocation['on_hand_share_paisa']);
                $movementId = null;

                if (! $onHandShare->isZero() && $allocation['new_unit_cost'] !== null) {
                    $movementId = $this->revalue->handle(
                        batch: $batch,
                        direction: MovementDirection::Out,
                        newUnitCost: $allocation['new_unit_cost'],
                        valueDelta: $onHandShare->toTaka(),
                        performedBy: $by->id,
                        note: $memo,
                        occurredAt: $on,
                        reason: MovementReason::PurchaseDiscount,
                    )->id;

                    $lines[] = JournalLineDraft::credit($stock->id, $onHandShare, "{$discount->number} batch {$batch->batch_number}");
                }

                $discount->allocations()->create([
                    'business_id' => $discount->business_id,
                    'goods_receipt_line_id' => $allocation['receipt_line_id'],
                    'stock_batch_id' => $batch->id,
                    'quantity_received' => $allocation['quantity_received'],
                    'quantity_on_hand' => $allocation['quantity_on_hand'],
                    'quantity_consumed' => $allocation['quantity_consumed'],
                    'share_paisa' => $allocation['share_paisa'],
                    'on_hand_share_paisa' => $allocation['on_hand_share_paisa'],
                    'consumed_share_paisa' => $allocation['consumed_share_paisa'],
                    'old_unit_cost' => $allocation['old_unit_cost'],
                    'new_unit_cost' => $allocation['new_unit_cost'],
                    'stock_movement_id' => $movementId,
                ]);
            }

            if ($plan['consumed'] > 0) {
                $lines[] = JournalLineDraft::credit($consumedHead->id, Money::ofMinor($plan['consumed']), "{$discount->number}: on stock already consumed or sold");
            }

            $lines = LedgerRounding::balance($lines, $on, "Rounding on discount {$discount->number}");

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: $lines,
                memo: $memo,
                source: $discount,
            ), $by);

            $discount->update(['journal_id' => $journal->id, 'party_adjustment_id' => $adjustment->id]);

            activity()->performedOn($discount)->causedBy($by)->log("Recorded {$discount->label()} {$discount->number} of {$amount->format()} on bill {$bill->bill_number}");

            return $discount->fresh(['allocations']);
        });
    }

    /**
     * ⚠⚠ Double counting (ruling test 22). One supplier reference is one
     * discount; and a bill that already took a 4950 `supplier_discount`
     * adjustment has had "its discount" already, so a second one on its goods
     * is refused until a person says which is right (and the reverse is
     * guarded in RecordPartyAdjustment).
     *
     * @param  callable(string): never  $refuse
     */
    private function assertNotCountedTwice(SupplierBill $bill, string $reference, callable $refuse): void
    {
        $same = SupplierDiscount::query()
            ->where('supplier_id', $bill->supplier_id)
            ->whereNull('reversed_at')
            ->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])
            ->value('number');

        if ($same !== null) {
            $refuse("Discount {$same} already records the supplier's reference {$reference}. The same discount cannot be counted twice.");
        }

        $adjusted = PartyAdjustmentAllocation::query()
            ->where('supplier_bill_id', $bill->id)
            ->whereHas('adjustment', fn ($query) => $query->where('kind', 'supplier_discount'))
            ->exists();

        if ($adjusted) {
            $refuse("Bill {$bill->bill_number} already has a discount received from the supplier (a supplier discount adjustment). Remove that adjustment first if this is the same discount.");
        }
    }
}
