<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\QuickPurchaseApprovals;
use App\Modules\Purchasing\Support\SupplierChallans;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * ⭐⭐ Buy and receive on one screen (supplier finance wave C, ruling P4,
 * 18 Oct 2026): goods bought and brought in the same day, entered once.
 *
 * ⚠⚠ AN ORCHESTRATOR ONLY. It writes no stock, no journal and no money of its
 * own. Inside ONE transaction it calls the actions the separate screens call,
 * in the order a careful clerk would:
 *
 *   1. CreatePurchaseOrder    PO-000123; approved on the spot only when the
 *                             clerk may approve orders (its own rule)
 *   2. RecordGoodsReceipt     stock in, Dr Stock / Cr GRNI (supplier-tagged)
 *   3. Save/Submit/PostLandedCost  the extra costs (carriage, unloading,
 *                             packaging...), LC-2026-27-0001, posted only when
 *                             the clerk may post landed costs
 *   4. RecordSupplierBill     Dr GRNI / Cr AP, with any trade discount
 *   5. RecordSupplierPayment  optional, part payment allowed; more than is
 *                             owed becomes an advance only when confirmed
 *
 * so every document keeps its own number, status and audit trail, and the
 * journals are exactly the ones the four separate screens would have posted.
 *
 * ⚠⚠ All or nothing: any refusal in any step rolls back every document and
 * journal already made in this submission. The one exception is a step that
 * STOPS FOR APPROVAL (QuickPurchaseApprovals): what came before it stays (the
 * goods physically arrived, so the receipt must not disappear from stock
 * control) and the result names what waits.
 *
 * ⚠⚠ Idempotent: the browser sends one request key per form; the same key
 * again (a double click, a retry after a timeout) returns the purchase it
 * already made instead of a second set of documents.
 *
 * ⚠ No approval bypass: each step needs the permission its own screen needs,
 * on top of `purchasing.quick.purchase`; holding po.approve gives no right to
 * pay, bill or post a landed cost.
 */
class RecordQuickPurchase
{
    /** What each step needs, as its own screen requires it. */
    private const ALWAYS = [
        'purchasing.quick.purchase' => 'use Buy and receive',
        'purchasing.po.create' => 'raise a purchase order',
        'purchasing.receipt.record' => 'receive goods',
        'purchasing.bill.record' => 'record a supplier bill',
    ];

    /**
     * ⭐ Ruling P7: the step that stopped for approval by THROWING, kept until
     * this purchase's transaction has committed and then saved as a PA- row
     * (QuickPurchaseApprovals::release), never inside the transaction.
     */
    private ?PurchaseApprovalRequired $pendingApproval = null;

    public function __construct(
        private readonly CreatePurchaseOrder $createOrder,
        private readonly RecordGoodsReceipt $receive,
        private readonly SaveLandedCost $saveLandedCost,
        private readonly SubmitLandedCost $submitLandedCost,
        private readonly PostLandedCost $postLandedCost,
        private readonly RecordSupplierBill $recordBill,
        private readonly RecordSupplierPayment $recordPayment,
    ) {}

    /**
     * An extra cost's `payee` is `supplier` (owed to this supplier, on its own
     * bill) or `money` (paid from the money account it names).
     *
     * @param  list<array{product: Product, quantity: string, unit_cost?: ?string, line_total?: ?string, entry_mode?: ?string, batch_number?: ?string, manufactured_date?: ?string, expiry_date?: ?string}>  $lines
     * @param  list<array{cost_type: string, amount: string, payee: string, money_account_id?: ?int, description?: ?string}>  $extraCosts
     * @param  array{money_account: MoneyAccount, amount: string, reference?: ?string, excess_to_advance?: bool}|null  $payment
     * @param  array{value: string, reference: string, reason?: ?string}|null  $tradeDiscount  an amount printed on the supplier's bill
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(
        string $requestKey,
        Supplier $supplier,
        Warehouse $warehouse,
        string $purchasedOn,
        array $lines,
        string $billNumber,
        User $by,
        ?string $challanNumber = null,
        ?string $dueDate = null,
        ?string $note = null,
        ?string $backdateReason = null,
        array $extraCosts = [],
        ?array $payment = null,
        ?array $tradeDiscount = null,
        // ⭐ Ruling P6: why a typed due date differs from the supplier's terms.
        ?string $dueDateReason = null,
        // ⭐ Ruling P5: why a bill that looks like an earlier one is recorded anyway.
        ?string $duplicateReason = null,
    ): QuickPurchase {
        $this->authorize($by, $extraCosts !== [], $payment, $tradeDiscount);
        $this->pendingApproval = null;

        // ⚠ A replay returns what the first submission made, before anything
        // else is checked: the first one may have used up the bill number.
        if (($done = $this->existing($requestKey)) !== null) {
            return $done;
        }

        $this->preflight($supplier, $billNumber, $challanNumber, $lines, $purchasedOn, $dueDate, $dueDateReason);

        try {
            $quick = DB::transaction(fn () => $this->run(
                $requestKey, $supplier, $warehouse, $purchasedOn, $lines, $billNumber, $by,
                $challanNumber, $dueDate, $note, $backdateReason, $extraCosts, $payment, $tradeDiscount,
                $dueDateReason, $duplicateReason,
            ));
        } catch (UniqueConstraintViolationException $e) {
            // ⚠⚠ Two submissions with the same key at once: the second insert
            // waited for the first transaction and failed on the unique index.
            // The first one's documents are the answer. Any other unique
            // refusal (a bill number saved by somebody else) is rethrown.
            return $this->existing($requestKey) ?? throw $e;
        }

        return $this->releasePending($quick, $by);
    }

    /**
     * ⭐ Ruling P7: the bill or payment that stopped for approval becomes a
     * PA- row now that the purchase has committed, and the purchase links it
     * (the result page opens it). Should the gate refuse to hold it (another
     * PA- row for the same bill number appeared meanwhile) the purchase says
     * so instead of pretending it waits.
     */
    private function releasePending(QuickPurchase $quick, User $by): QuickPurchase
    {
        $required = $this->pendingApproval;
        $this->pendingApproval = null;

        if ($required === null) {
            return $quick;
        }

        try {
            $approval = app(QuickPurchaseApprovals::class)->release($required, $by);
        } catch (ValidationException $e) {
            $quick->forceFill(['held_reason' => trim($quick->held_reason.' It could not be sent for approval: '.collect($e->errors())->flatten()->first())])->save();

            return $quick;
        }

        $quick->forceFill([
            'purchase_approval_id' => $approval->id,
            'held_reason' => trim("Saved as {$approval->number()}. ".$quick->held_reason),
        ])->save();

        return $quick;
    }

    private function run(
        string $requestKey,
        Supplier $supplier,
        Warehouse $warehouse,
        string $purchasedOn,
        array $lines,
        string $billNumber,
        User $by,
        ?string $challanNumber,
        ?string $dueDate,
        ?string $note,
        ?string $backdateReason,
        array $extraCosts,
        ?array $payment,
        ?array $tradeDiscount,
        ?string $dueDateReason,
        ?string $duplicateReason,
    ): QuickPurchase {
        $approvals = app(QuickPurchaseApprovals::class);
        $waiting = [];

        // ⚠ First, so a concurrent twin blocks here and nothing is half-made.
        $quick = QuickPurchase::create([
            'request_key' => $requestKey,
            'supplier_id' => $supplier->id,
            'purchased_on' => $purchasedOn,
            'status' => QuickPurchase::COMPLETED,
            'created_by' => $by->id,
        ]);

        // ── 1. The purchase order ───────────────────────────────────────────
        $order = $this->step(null, fn () => $this->createOrder->handle(
            supplier: $supplier,
            warehouse: $warehouse,
            lines: array_map(fn (array $line) => [
                'product' => $line['product'],
                'quantity' => (string) $line['quantity'],
                'unit_cost' => $line['unit_cost'] ?? null,
                'line_total' => $line['line_total'] ?? null,
                'entry_mode' => $line['entry_mode'] ?? null,
            ], $lines),
            createdBy: $by,
            note: $note,
            orderDate: $purchasedOn,
        ));

        $orderLines = $order->lines()->orderBy('id')->get();
        $goods = Money::sum($orderLines->map(fn (PurchaseOrderLine $line) => $line->lineTotal())->all());

        $quick->fill(['purchase_order_id' => $order->id, 'goods_paisa' => $goods->minor]);

        if ($order->fresh('approvalRequest')->approvalRequest->isPending()) {
            // ⚠ Ruling P4: without the authority, the purchase is saved as a
            // request and nothing else is posted. The goods are received from
            // the order once a person who may approve it has.
            return $this->hold($quick, QuickPurchase::AWAITING_ORDER_APPROVAL,
                "Purchase order {$order->po_number} waits for approval by someone who may approve orders. Nothing was received, billed or paid: receive the goods from the order once it is approved.");
        }

        // ── 2. The goods receipt ────────────────────────────────────────────
        $receipt = $this->step(null, fn () => $this->receive->handle(
            order: $order->fresh(['approvalRequest', 'warehouse', 'supplier']),
            lineReceipts: $orderLines->values()->map(fn (PurchaseOrderLine $line, int $index) => [
                'line' => $line,
                'quantity' => (string) $line->quantity_ordered,
                'batch_number' => $lines[$index]['batch_number'] ?? null,
                'manufactured_date' => $lines[$index]['manufactured_date'] ?? null,
                'expiry_date' => $lines[$index]['expiry_date'] ?? null,
            ])->all(),
            receivedBy: $by,
            note: $note,
            supplierChallanNumber: $challanNumber,
            receivedOn: $purchasedOn,
            backdateReason: $backdateReason,
        ));

        $quick->goods_receipt_id = $receipt->id;

        $extras = Money::sum(array_map(fn (array $cost) => Money::ofTaka((string) $cost['amount']), $extraCosts));
        $quick->extra_costs_paisa = $extras->minor;

        // ── Approval of the whole purchase (ruling P7) ──────────────────────
        // ⚠ The gross commitment, not what is paid today. When it needs a
        // second person, the bill carries it as its approval amount, so the
        // bill is the document that waits (a PA- row that posts it as typed).
        $commitment = $goods->plus($extras);
        $discount = $tradeDiscount === null ? null : Money::ofTaka((string) $tradeDiscount['value']);
        $approvalBasis = $approvals->commitmentHold($commitment, $supplier, $by, $discount, $goods) !== null ? $commitment : null;

        // ── 3. The extra costs, through the landed-cost engine ──────────────
        $landedCost = null;

        if ($extraCosts !== []) {
            $landedCost = $this->landedCost($receipt, $purchasedOn, $extraCosts, $supplier, $by, $order);
            $quick->landed_cost_id = $landedCost->id;

            if ($landedCost->status !== LandedCostStatus::Posted) {
                $waiting[] = "Landed cost {$landedCost->number} waits for approval by someone who may post landed costs; the extra costs reach the stock value when it is approved and posted.";
            }
        }

        // ── 4. The supplier bill ────────────────────────────────────────────
        try {
            // ⚠ Its own savepoint: a bill that stops for approval by THROWING
            // takes only itself back, never the receipt before it.
            $bill = DB::transaction(fn () => $this->step(null, fn () => $this->recordBill->handle(
                supplier: $supplier,
                billNumber: $billNumber,
                billDate: $purchasedOn,
                lines: $orderLines->map(fn (PurchaseOrderLine $line) => [
                    'line' => $line,
                    'quantity' => (string) $line->quantity_ordered,
                    // The figure that was typed, so the bill equals the order to the paisa.
                    ...($line->entry_mode === PurchaseEntryMode::Total
                        ? ['lineTotal' => $line->lineTotal()->toTaka(), 'entryMode' => PurchaseEntryMode::Total->value]
                        : ['unitCost' => (string) $line->unit_cost, 'entryMode' => PurchaseEntryMode::Rate->value]),
                ])->all(),
                createdBy: $by,
                purchaseOrder: $order,
                dueDate: $dueDate,
                note: $note,
                tradeDiscount: $tradeDiscount === null ? null : [
                    'mode' => 'amount',
                    'value' => (string) $tradeDiscount['value'],
                    'reference' => (string) $tradeDiscount['reference'],
                    'reason' => $tradeDiscount['reason'] ?? null,
                ],
                // ⭐ Ruling P6: the due date is snapshotted from the terms as
                // on the bill screen; a typed date that differs needs its reason.
                dueDateReason: $dueDateReason,
                // ⭐ Ruling P5: a person is here to answer the duplicate question.
                duplicateReason: $duplicateReason,
                warnOnLikelyDuplicates: true,
                // ⭐ Ruling P7: the whole purchase, when it needs approval.
                approvalBasis: $approvalBasis,
            )));
        } catch (Throwable $e) {
            if (($hold = $approvals->holdFrom($e)) === null) {
                throw $e;
            }

            $this->pendingApproval = $e instanceof PurchaseApprovalRequired ? $e : null;

            return $this->hold($quick, QuickPurchase::AWAITING_BILL_APPROVAL, $hold, $waiting);
        }

        $quick->supplier_bill_id = $bill->id;

        if (($hold = $approvals->billHold($bill)) !== null) {
            return $this->hold($quick, QuickPurchase::AWAITING_BILL_APPROVAL, $hold, $waiting);
        }

        // ── 5. The payment, when one was made ───────────────────────────────
        if ($payment !== null && Money::ofTaka((string) $payment['amount'])->minor > 0) {
            try {
                $paid = DB::transaction(fn () => $this->pay($supplier, $bill, $landedCost, $purchasedOn, $payment, $by, $note));
            } catch (Throwable $e) {
                if (($hold = $approvals->holdFrom($e)) === null) {
                    throw $e;
                }

                $this->pendingApproval = $e instanceof PurchaseApprovalRequired ? $e : null;

                return $this->hold($quick, QuickPurchase::AWAITING_PAYMENT_APPROVAL, $hold, $waiting);
            }

            $quick->fill(['supplier_payment_id' => $paid->id, 'paid_paisa' => Money::ofTaka((string) $payment['amount'])->minor]);

            if (($hold = $approvals->paymentHold($paid)) !== null) {
                return $this->hold($quick, QuickPurchase::AWAITING_PAYMENT_APPROVAL, $hold, $waiting);
            }
        }

        $quick->fill(['held_reason' => $waiting === [] ? null : implode(' ', $waiting)])->save();

        return $quick;
    }

    /**
     * The extra costs as ONE landed cost on this receipt, spread by value,
     * each type on its own default treatment (carriage, unloading and
     * packaging into the stock cost; travel and bank charges an expense).
     * Posted at once when the clerk may post landed costs, as the landed-cost
     * screen does; otherwise sent for approval.
     *
     * @param  list<array{cost_type: string, amount: string, payee: string, money_account_id?: ?int, description?: ?string}>  $extraCosts
     */
    private function landedCost(GoodsReceipt $receipt, string $on, array $extraCosts, Supplier $supplier, User $by, PurchaseOrder $order): LandedCost
    {
        $draft = $this->step(fn (string $key) => preg_replace('/^lines\./', 'costs.', $key), fn () => $this->saveLandedCost->handle(
            receipt: $receipt,
            incurredOn: $on,
            lines: array_map(fn (array $cost) => [
                'cost_type' => $cost['cost_type'],
                'treatment' => null,
                'override_reason' => null,
                'allocation_method' => 'value',
                'goods_receipt_line_id' => null,
                'amount' => (string) $cost['amount'],
                'supplier_id' => $cost['payee'] === 'supplier' ? $supplier->id : null,
                'money_account_id' => $cost['payee'] === 'money' ? ($cost['money_account_id'] ?? null) : null,
                'expense_account_id' => null,
                'description' => $cost['description'] ?? null,
            ], $extraCosts),
            actor: $by,
            note: "Extra costs of {$order->po_number}, entered on Buy and receive",
        ));

        $submitted = $this->step(null, fn () => $this->submitLandedCost->handle($draft, $by));

        if ($submitted->status !== LandedCostStatus::Approved) {
            return $submitted;
        }

        return $this->step(null, fn () => $this->postLandedCost->handle($submitted, $by));
    }

    /**
     * One payment, against the goods bill first and then the bill the extra
     * costs owed to this supplier raised. Whatever is left over goes onto the
     * goods bill's line, where RecordSupplierPayment refuses it, or (confirmed)
     * keeps it as an advance: wave A's rule, not a second copy of it.
     *
     * @param  array{money_account: MoneyAccount, amount: string, reference?: ?string, excess_to_advance?: bool}  $payment
     */
    private function pay(Supplier $supplier, SupplierBill $bill, ?LandedCost $landedCost, string $on, array $payment, User $by, ?string $note): SupplierPayment
    {
        $bills = collect([$bill->fresh()]);

        if ($landedCost !== null && $landedCost->status === LandedCostStatus::Posted) {
            $bills = $bills->merge($landedCost->bills()->where('supplier_id', $supplier->id)->orderBy('id')->get());
        }

        $left = Money::ofTaka((string) $payment['amount'])->minor;
        $allocations = [];

        foreach ($bills as $open) {
            $take = min($left, max(0, $open->outstanding()->minor));

            if ($take > 0) {
                $allocations[] = ['bill' => $open, 'amount' => Money::ofMinor($take)->toTaka()];
                $left -= $take;
            }
        }

        if ($left > 0) {
            $allocations === []
                ? $allocations[] = ['bill' => $bills->first(), 'amount' => Money::ofMinor($left)->toTaka()]
                : $allocations[0]['amount'] = Money::ofMinor(Money::ofTaka($allocations[0]['amount'])->minor + $left)->toTaka();
        }

        return $this->step(fn (string $key) => match (true) {
            str_starts_with($key, 'allocations') => 'payment_amount',
            $key === 'paid_on' => 'purchased_on',
            default => $key,
        }, fn () => $this->recordPayment->handle(
            supplier: $supplier,
            moneyAccount: $payment['money_account'],
            paidOn: $on,
            allocations: $allocations,
            createdBy: $by,
            reference: $payment['reference'] ?? null,
            note: $note,
            excessToAdvance: (bool) ($payment['excess_to_advance'] ?? false),
        ));
    }

    /**
     * Runs one step and puts its refusal under the box it is about: a
     * validation key renamed for this screen by `$under`, a closed period
     * under the date. Any other refusal passes on unchanged (the controller
     * shows it above the form).
     *
     * @template T
     *
     * @param  (callable(string): string)|null  $under
     * @param  callable(): T  $step
     * @return T
     */
    private function step(?callable $under, callable $step): mixed
    {
        try {
            return $step();
        } catch (PurchaseApprovalRequired $e) {
            // ⭐ Ruling P7: a step that waits for approval passes on as it is,
            // so the hold can save it; it is not a refusal to rename.
            throw $e;
        } catch (ValidationException $e) {
            if ($under === null) {
                throw $e;
            }

            $messages = [];

            foreach ($e->errors() as $key => $lines) {
                $messages[$under($key)] = array_merge($messages[$under($key)] ?? [], $lines);
            }

            throw ValidationException::withMessages($messages);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['purchased_on' => $e->getMessage()]);
        }
    }

    /** @param  list<string>  $waiting */
    private function hold(QuickPurchase $quick, string $status, string $reason, array $waiting = []): QuickPurchase
    {
        $quick->fill(['status' => $status, 'held_reason' => implode(' ', [$reason, ...$waiting])])->save();

        return $quick;
    }

    private function existing(string $requestKey): ?QuickPurchase
    {
        return QuickPurchase::query()->where('request_key', $requestKey)->first();
    }

    /**
     * ⚠ Every step's own permission, checked BEFORE anything is written. The
     * steps' actions mostly trust their screens to check, so this is the one
     * place the quick screen proves it is not a way round them.
     *
     * @throws AuthorizationException
     */
    private function authorize(User $by, bool $hasExtraCosts, ?array $payment, ?array $tradeDiscount): void
    {
        $needed = self::ALWAYS;

        if ($hasExtraCosts) {
            $needed['purchasing.landed_cost.manage'] = 'enter landed costs';
        }

        if ($payment !== null && Money::ofTaka((string) $payment['amount'])->minor > 0) {
            $needed['purchasing.payment.record'] = 'pay a supplier';

            if ((bool) ($payment['excess_to_advance'] ?? false)) {
                $needed['purchasing.advance.record'] = 'keep an overpayment as an advance';
            }
        }

        $missing = array_values(array_filter($needed, fn (string $label, string $permission) => ! $by->can($permission), ARRAY_FILTER_USE_BOTH));

        if ($missing !== []) {
            throw new AuthorizationException('Buy and receive needs every right its steps need. You may not '.implode(', ', $missing).'.');
        }
    }

    /**
     * The refusals a clerk can fix from this screen, checked before the
     * transaction so they land under their own box and claim no PO number.
     * The actions check each again inside the transaction.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function preflight(Supplier $supplier, string $billNumber, ?string $challanNumber, array $lines, string $purchasedOn, ?string $dueDate, ?string $dueDateReason): void
    {
        $problems = [];

        if ($lines === []) {
            $problems['lines'] = 'Add at least one item.';
        }

        foreach ($lines as $index => $line) {
            foreach (PurchaseLineEntry::problems((string) $line['quantity'], $line['unit_cost'] ?? null, $line['line_total'] ?? null, $line['entry_mode'] ?? null) as $field => $problem) {
                $problems["lines.{$index}.{$field}"] = $problem;
            }
        }

        if (trim($billNumber) === '') {
            $problems['bill_number'] = "Type the number on the supplier's invoice or cash memo.";
        } elseif (SupplierBill::query()->where('supplier_id', $supplier->id)->where('bill_number', $billNumber)->whereNull('reversed_at')->exists()) {
            $problems['bill_number'] = "Bill {$billNumber} has already been recorded for {$supplier->name}.";
        } elseif (($waiting = PurchaseApproval::query()->where('supplier_id', $supplier->id)->where('kind', 'bill')
            ->where('reference', $billNumber)->where('status', PurchaseApproval::PENDING)->first()) !== null) {
            // ⭐ Ruling P7: the same bill already waits for approval.
            $problems['bill_number'] = "Bill {$billNumber} from {$supplier->name} is already waiting for approval as {$waiting->number()}.";
        }

        // ⭐ Ruling P6: the order this screen raises has no terms of its own,
        // so the bill's due date comes from the supplier's; a typed date that
        // differs needs its reason, asked here under its own box.
        try {
            PaymentTerms::snapshot($supplier, null, Carbon::parse($purchasedOn), $dueDate, null, $dueDateReason);
        } catch (InvalidArgumentException $e) {
            $problems['due_date_reason'] = $e->getMessage();
        }

        if (($refusal = SupplierChallans::refusal((int) $supplier->id, $challanNumber, $supplier->name)) !== null) {
            $problems['challan_number'] = $refusal;
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
}
