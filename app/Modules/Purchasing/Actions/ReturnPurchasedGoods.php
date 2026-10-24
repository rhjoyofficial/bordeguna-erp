<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): "Return" from
 * the goods receipt or the supplier bill, in ONE step, through the existing
 * engines:
 *
 *  1. the goods go back from the exact batches that delivery made
 *     (RecordPurchaseReturn restricted to its receipt lines); a batch already
 *     used up refuses (ReverseStockReceipt, never negative). The returned
 *     goods' share of landed cost goes to the loss head with the reason
 *     (PostPurchaseJournals::forReturn).
 *  2. the part of the return that was BILLED is what the supplier owes back:
 *     a credit note reduces what the open bills of this order still owe
 *     (RecordSupplierCreditNote), the bill it was started from first.
 *  3. what a paid bill could not take: the user chooses (Q21). Either it is
 *     kept as a supplier advance (offered on the next purchase by "Allocate
 *     advance"), or the supplier pays it back now: refund lines into money
 *     accounts (split allowed) out of that advance (RefundSupplierAdvance).
 *     What the refund lines do not cover stays an advance.
 *
 * Goods that were never billed need nothing more: the return relieves GRNI
 * and the bill, when it comes, is for less (billableQuantity).
 *
 * ⚠ All of it in one transaction. The one exception is a refund big enough to
 * need a second person (purchasing approvals, ruling P7): the gate refuses
 * before writing anything, so the return and the advance stand and the refund
 * is saved as pending AFTER the commit, posting when approved.
 */
class ReturnPurchasedGoods
{
    public const SETTLE_ADVANCE = 'advance';

    public const SETTLE_REFUND = 'refund';

    public function __construct(
        private readonly RecordPurchaseReturn $returns,
        private readonly RecordSupplierCreditNote $creditNotes,
        private readonly RecordSupplierAdvance $advances,
        private readonly RefundSupplierAdvance $refunds,
        private readonly PurchasingApprovalGate $approvals,
    ) {}

    /**
     * The part of each line's return quantity that was already billed, valued
     * at the order price: returned goods first use up what is still unbilled.
     *
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string}>  $lineReturns
     */
    public static function billedValue(array $lineReturns): Money
    {
        $scale = fn (string $value) => (int) round(((float) $value) * 10000);
        $total = Money::zero();

        foreach ($lineReturns as $entry) {
            $line = PurchaseOrderLine::query()->findOrFail($entry['line']->id);
            $unbilled = max(0, $scale((string) $line->quantity_received) - $scale((string) $line->quantity_returned) - $scale((string) $line->quantity_invoiced));
            $billed = max(0, $scale((string) $entry['quantity']) - $unbilled);

            if ($billed > 0) {
                $total = $total->plus($line->valueOf(number_format($billed / 10000, 4, '.', '')));
            }
        }

        return $total;
    }

    /**
     * The live bills of this order that still owe something, the one the
     * return was started from first, then oldest first.
     *
     * @return Collection<int, SupplierBill>
     */
    public static function openBillsOf(PurchaseOrder $order, ?SupplierBill $first = null)
    {
        $lineIds = $order->lines()->pluck('id');

        return SupplierBill::query()
            ->where('supplier_id', $order->supplier_id)
            ->whereNull('reversed_at')
            ->where(fn (Builder $q) => $q->where('purchase_order_id', $order->id)
                ->orWhereHas('lines', fn (Builder $l) => $l->whereIn('purchase_order_line_id', $lineIds)))
            ->orderBy('bill_date')->orderBy('id')
            ->get()
            ->sortBy(fn (SupplierBill $bill) => $first !== null && $bill->id === $first->id ? 0 : 1)
            ->filter(fn (SupplierBill $bill) => $bill->outstanding()->minor > 0)
            ->values();
    }

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string}>  $lineReturns
     * @param  list<array{money_account: MoneyAccount, amount: string}>  $refundLines
     * @return array{return: PurchaseReturn, credit_note: ?SupplierCreditNote, advance: ?SupplierAdvance, refunds: list<SupplierAdvanceRefund>, pending: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(
        PurchaseOrder $order,
        array $lineReturns,
        string $returnedOn,
        string $reason,
        User $by,
        ?GoodsReceipt $receipt = null,
        ?SupplierBill $bill = null,
        string $settle = self::SETTLE_ADVANCE,
        array $refundLines = [],
        ?string $creditNoteNumber = null,
    ): array {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say why the goods go back (at least 5 characters).']);
        }

        if (! in_array($settle, [self::SETTLE_ADVANCE, self::SETTLE_REFUND], true)) {
            throw ValidationException::withMessages(['settle' => 'Choose whether the supplier keeps the credit as an advance or pays it back.']);
        }

        $on = Carbon::parse($returnedOn)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['returned_on' => 'A return cannot be dated in the future.']);
        }

        $receiptLineIds = $this->receiptLineIds($order, $receipt, $bill);
        $earliest = $this->earliestDelivery($order, $receiptLineIds);

        if ($earliest !== null && $on->lt($earliest)) {
            throw ValidationException::withMessages(['returned_on' => 'The goods cannot go back before they arrived ('.$earliest->format('d/m/Y').').']);
        }

        $lineReturns = array_values(array_filter($lineReturns, fn (array $entry) => (float) $entry['quantity'] > 0));

        if ($lineReturns === []) {
            throw ValidationException::withMessages(['lines' => 'Enter how much of at least one item goes back.']);
        }

        $refundLines = array_values(array_filter($refundLines, fn (array $row) => Money::ofTaka((string) $row['amount'])->minor > 0));

        $pending = [];

        $result = DB::transaction(function () use ($order, $lineReturns, $on, $reason, $by, $receiptLineIds, $bill, $settle, $refundLines, $creditNoteNumber, &$pending) {
            // Measured BEFORE the return moves quantity_returned.
            $billed = self::billedValue($lineReturns);

            $return = $this->returns->handle($order, $lineReturns, $by, $reason, $receiptLineIds, $on->toDateString());

            $credit = Money::ofMinor(min($billed->minor, $return->invoiceableValue()->minor));
            $creditNote = null;
            $advance = null;
            $refunds = [];

            if ($credit->minor <= 0) {
                if ($settle === self::SETTLE_REFUND && $refundLines !== []) {
                    throw ValidationException::withMessages(['refunds' => 'These goods were never billed, so the supplier owes nothing back: the bill, when it comes, is for less.']);
                }

                return ['return' => $return, 'credit_note' => null, 'advance' => null, 'refunds' => []];
            }

            // Unpaid (or part-paid) bills first: the credit reduces what we owe.
            $left = $credit;
            $allocations = [];

            foreach (self::openBillsOf($order, $bill) as $open) {
                if ($left->minor <= 0) {
                    break;
                }

                $take = Money::ofMinor(min($left->minor, $open->outstanding()->minor));
                $allocations[] = ['bill' => $open, 'amount' => $take->toTaka()];
                $left = Money::ofMinor($left->minor - $take->minor);
            }

            if ($allocations !== []) {
                $creditNote = $this->creditNotes->handle(
                    supplier: $order->supplier,
                    purchaseReturn: $return,
                    creditNoteNumber: trim((string) $creditNoteNumber) !== '' ? trim((string) $creditNoteNumber) : "RET-{$return->id}",
                    creditDate: $on->toDateString(),
                    allocations: $allocations,
                    createdBy: $by,
                    note: $reason,
                );
            }

            // The bill was already paid for this much: Q21, the user's choice.
            if ($left->minor > 0) {
                $advance = $this->advances->forReturnCredit($return->fresh('purchaseOrder.supplier'), $left, $on, $reason, $by);

                if ($settle === self::SETTLE_REFUND) {
                    $refunds = $this->refund($advance, $refundLines, $on, $by, $pending);
                }
            } elseif ($settle === self::SETTLE_REFUND && $refundLines !== []) {
                throw ValidationException::withMessages(['refunds' => 'The bill still owed more than these goods are worth, so the credit reduced the bill and nothing is left to refund.']);
            }

            return ['return' => $return, 'credit_note' => $creditNote, 'advance' => $advance, 'refunds' => $refunds];
        });

        // A refund that needs a second person: saved as pending now that the
        // advance it comes out of exists.
        $held = [];

        foreach ($pending as $required) {
            $held[] = $this->approvals->hold($required, $by)->number();
        }

        return [...$result, 'pending' => $held];
    }

    /**
     * @param  list<array{money_account: MoneyAccount, amount: string}>  $refundLines
     * @param  list<PurchaseApprovalRequired>  $pending
     * @return list<SupplierAdvanceRefund>
     */
    private function refund(SupplierAdvance $advance, array $refundLines, Carbon $on, User $by, array &$pending): array
    {
        if ($refundLines === []) {
            throw ValidationException::withMessages(['refunds' => 'Say which account the supplier\'s money comes back into, and how much.']);
        }

        $total = Money::sum(array_map(fn (array $row) => Money::ofTaka((string) $row['amount']), $refundLines));

        if ($total->minor > $advance->money()->minor) {
            throw ValidationException::withMessages(['refunds' => "The supplier owes back {$advance->money()->format()}; the refund lines add up to {$total->format()}."]);
        }

        $ids = array_map(fn (array $row) => $row['money_account']->id, $refundLines);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['refunds' => 'The same account appears twice. Put its money on one line.']);
        }

        $refunds = [];

        foreach ($refundLines as $row) {
            try {
                $refunds[] = $this->refunds->handle(
                    supplier: $advance->supplier,
                    advance: $advance,
                    moneyAccount: $row['money_account'],
                    refundedOn: $on->toDateString(),
                    amount: Money::ofTaka((string) $row['amount'])->toTaka(),
                    by: $by,
                    reference: $advance->reference,
                    note: 'Supplier paid back the credit for goods returned',
                );
            } catch (PurchaseApprovalRequired $required) {
                $pending[] = $required;
            }
        }

        return $refunds;
    }

    /**
     * The goods receipt lines whose batches this return may touch: that
     * receipt's, or the deliveries the bill billed; null = every delivery of
     * the order (the order page, as before).
     *
     * @return list<int>|null
     */
    private function receiptLineIds(PurchaseOrder $order, ?GoodsReceipt $receipt, ?SupplierBill $bill): ?array
    {
        if ($receipt !== null) {
            if ((int) $receipt->purchase_order_id !== (int) $order->id) {
                throw ValidationException::withMessages(['lines' => 'That delivery is not on this purchase order.']);
            }

            if ($receipt->isReversed()) {
                throw ValidationException::withMessages(['lines' => 'That delivery was reversed; there is nothing to send back.']);
            }

            return $receipt->lines()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($bill !== null) {
            if ($bill->isReversed()) {
                throw ValidationException::withMessages(['lines' => "Bill {$bill->bill_number} was reversed."]);
            }

            $ids = $bill->lines()->whereNotNull('goods_receipt_line_id')->pluck('goods_receipt_line_id')->map(fn ($id) => (int) $id)->all();

            return $ids === [] ? null : $ids;
        }

        return null;
    }

    private function earliestDelivery(PurchaseOrder $order, ?array $receiptLineIds): ?Carbon
    {
        $received = GoodsReceipt::query()
            ->where('purchase_order_id', $order->id)
            ->when($receiptLineIds !== null, fn (Builder $q) => $q->whereHas('lines', fn (Builder $l) => $l->whereIn('id', $receiptLineIds)))
            ->min('received_at');

        return $received === null ? null : Carbon::parse($received)->startOfDay();
    }
}
