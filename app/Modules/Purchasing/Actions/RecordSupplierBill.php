<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Accounting\Support\TaxSplit;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Support\LikelyDuplicateBill;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\ReceiptBilling;
use App\Modules\Purchasing\Support\SupplierBillDuplicates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a supplier's invoice.
 *
 * ⚠⚠ This is the document Accounts Payable is a ledger of, and it did not
 * exist. Goods arrive in one month and the bill arrives in another; the gap
 * between what was received and what has been invoiced is Goods Received Not
 * Invoiced, and it is the accrual that makes accrual accounting mean anything.
 *
 * ✅ Phase 3: it now posts. Dr Goods Received Not Invoiced, Cr Accounts Payable
 * with the supplier as the line's party - which is what turns one payables
 * balance into a ledger somebody can act on. The receipt credited GRNI and this
 * debits it, so the two clear each other and a GRNI balance is a real report:
 * delivered but not yet invoiced.
 */
class RecordSupplierBill
{
    public function __construct(
        private readonly PostPurchaseJournals $posting,
        private readonly TaxCalculator $tax,
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026).
        private readonly RecordSupplierDiscount $discounts,
        // ⭐ Ruling P5 (19 Oct 2026): carriage on the bill, through landed cost.
        private readonly RaiseBillCarriage $carriage,
        // ⭐ Purchasing approvals (ruling P7, 18 Oct 2026).
        private readonly PurchasingApprovalGate $approvals,
    ) {}

    /**
     * Each line carries its quantity and EITHER `unitCost` OR `lineTotal`
     * (17 Oct 2026, Phase 1 part A); `entryMode` says which was typed when
     * both are sent. A line with only `unitCost` is a rate entry, as before.
     *
     * ⭐ Ruling P5 (19 Oct 2026): a goods line may also name the delivery it
     * bills (`receiptLine`), and with no `$purchaseOrder` the lines may come
     * from several orders of the SAME supplier. `$expenseLines` are service
     * or other non-PO lines (Dr the expense head / Cr Accounts Payable);
     * `$carriageLines` go through the landed-cost engine (RaiseBillCarriage),
     * never into this bill's total. `$duplicateReason` records the bill even
     * though it looks like an earlier one (SupplierBillDuplicates).
     *
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string, unitCost?: ?string, lineTotal?: ?string, entryMode?: ?string, receiptLine?: ?GoodsReceiptLine}>  $lines
     * @param  array<int, array{account: Account, amount: string, description?: ?string}>  $expenseLines
     * @param  array<int, array<string, mixed>>  $carriageLines
     */
    public function handle(
        Supplier $supplier,
        string $billNumber,
        mixed $billDate,
        array $lines,
        User $createdBy,
        ?PurchaseOrder $purchaseOrder = null,
        mixed $dueDate = null,
        ?string $note = null,
        // ⭐ VAT (plan D7). Does the unit cost typed from the supplier's bill
        // already contain the VAT? Read only while this business charges VAT.
        // Each line may also carry `taxRate` (the percentage the SUPPLIER
        // printed, which is the truth for a purchase) and `taxRecoverable`.
        bool $pricesIncludeTax = false,
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the trade discount
        // printed on the supplier's bill. {mode: amount|percent|lines, value,
        // reference, reason?, lines?: [purchase_order_line_id => taka]}.
        ?array $tradeDiscount = null,
        array $expenseLines = [],
        array $carriageLines = [],
        ?string $duplicateReason = null,
        // ⭐ Ruling P5: ask SupplierBillDuplicates before saving. The bill
        // screen always asks; other callers (opening balances, imports, the
        // later one-screen purchase) opt in when a person is there to answer.
        bool $warnOnLikelyDuplicates = false,
        // ⭐ Payment terms (ruling P6): terms the bill itself states when they
        // differ from the order's or supplier's, and the reason for any due
        // date that is not the one the terms give.
        ?PaymentTerms $terms = null,
        ?string $dueDateReason = null,
        // ⭐ Purchasing approvals (ruling P7): the whole commitment this bill
        // is part of, when that is more than the bill (Buy and receive: the
        // goods plus the extra costs). The approval limit is judged on the
        // larger of the two, so a purchase cannot dodge it by splitting.
        ?Money $approvalBasis = null,
    ): SupplierBill {
        $date = Carbon::parse($billDate);

        // The generalised period lock. A bill dated into a closed month would
        // change a figure somebody has already reported.
        AccountingPeriod::assertOpenFor($date);

        // ⚠⚠ The oldest control in accounts payable, checked before anything is
        // written: the same invoice number from the same supplier twice is how a
        // business pays for one delivery twice. The database enforces it too;
        // this exists to fail with a sentence rather than a constraint violation.
        //
        // ⚠ PUR-004 (owner decision 14 Oct 2026): only a LIVE bill holds its
        // number. A reversed bill's number is free for the corrected bill, so
        // the stored number matches the supplier's paper; the database index
        // on `active_number_key` says the same (see the 14 Oct migration).
        $duplicate = SupplierBill::where('supplier_id', $supplier->id)
            ->where('bill_number', $billNumber)
            ->whereNull('reversed_at')
            ->first();

        if ($duplicate !== null) {
            throw new InvalidArgumentException(
                "Bill {$billNumber} has already been recorded for {$supplier->name}. If that bill is wrong, reverse it first; its number is then free for the corrected bill."
            );
        }

        $expenseLines = $this->checkedExpenseLines($expenseLines, $date);

        $business = Business::withoutGlobalScopes()->find($supplier->business_id ?? app(BusinessContext::class)->id());
        $taxed = $this->tax->appliesOn($business, $date);

        // ⭐ Ruling P6: the due date is worked out ONCE, here, and stored with
        // a copy of the terms it came from. Nothing recomputes it later.
        $dueSnapshot = PaymentTerms::snapshot($supplier, $purchaseOrder, $date, $dueDate, $terms, $dueDateReason);

        return DB::transaction(function () use ($supplier, $billNumber, $date, $lines, $createdBy, $purchaseOrder, $dueDate, $dueSnapshot, $note, $business, $taxed, $pricesIncludeTax, $tradeDiscount, $expenseLines, $carriageLines, $duplicateReason, $warnOnLikelyDuplicates, $terms, $dueDateReason, $approvalBasis) {
            $bill = SupplierBill::create([
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $purchaseOrder?->id,
                'bill_number' => $billNumber,
                'bill_date' => $date,
                ...$dueSnapshot,
                'note' => $note,
                'created_by' => $createdBy->id,
                'total' => 0,
                // ⭐ Ruling P5: the approvals exception reads this flag.
                'has_non_po_lines' => $expenseLines !== [],
            ]);

            /** @var list<Money> $lineTotals */
            $lineTotals = [];
            // Purchasing approvals (ruling P7): billed value vs the order price.
            $priceLines = [];
            $taxTotal = Money::zero();

            foreach ($lines as $entry) {
                // ⚠ Re-read under a lock, the same lesson as I3: two people
                // billing the same delivery from stale copies would each pass
                // the check below and the second would overwrite the first.
                $line = PurchaseOrderLine::whereKey($entry['line']->id)->lockForUpdate()->firstOrFail();

                // ⚠⚠ D-SB-1. ONE BILL, ONE PURCHASE ORDER. The bill carries a
                // single `purchase_order_id`, and nothing checked that the lines
                // belonged to it, so a bill could name one order and invoice
                // another order's goods. The header then said one thing and the
                // lines did another, and the three-way match silently guarded
                // the wrong order.
                if ($purchaseOrder !== null && $line->purchase_order_id !== $purchaseOrder->id) {
                    throw new InvalidArgumentException(
                        "Line #{$line->id} belongs to a different purchase order. A bill covers exactly one order."
                    );
                }

                // ⭐⭐ Ruling P5 (19 Oct 2026): with no single order named, the
                // lines may come from several orders, but ALL of ONE supplier.
                // A bill is one supplier's invoice; goods bought from somebody
                // else are refused, never quietly owed to the wrong supplier.
                if ((int) PurchaseOrder::query()->whereKey($line->purchase_order_id)->value('supplier_id') !== (int) $supplier->id) {
                    throw new InvalidArgumentException(
                        "Line #{$line->id} was ordered from a different supplier. One bill covers one supplier's goods; record that supplier's bill separately."
                    );
                }

                $receiptLine = $this->checkedReceiptLine($entry, $line);

                // ⚠ Integer-scaled, not float. Quantities are decimal(15,4), so
                // four places are the whole domain and scaling by 10,000 makes
                // every comparison exact. The float version could pass a bill
                // for a hair more than arrived; the same lesson ConsumesFifoStock
                // records, applied to the money side.
                $quantity = $this->scaled($entry['quantity']);
                $alreadyInvoiced = $this->scaled((string) $line->quantity_invoiced);
                $received = $this->scaled((string) $line->quantity_received);
                $returned = $this->scaled((string) $line->quantity_returned);

                // ⚠⚠ The three-way match, D-SB-8. A supplier billing for twelve
                // when eight arrived is the most common overpayment in a small
                // business, and the system knows what arrived.
                //
                // ⚠⚠ RECEIVED MINUS RETURNED, not received. Goods that came in
                // and went back are not billable, and the old check compared
                // against arrivals alone, so a delivery that was returned in
                // full could still be invoiced in full.
                $billable = $received - $returned;

                if ($alreadyInvoiced + $quantity > $billable) {
                    $remaining = $this->unscaled(max(0, $billable - $alreadyInvoiced));

                    throw new InvalidArgumentException(
                        "Cannot invoice {$entry['quantity']} of line #{$line->id}: only {$remaining} of what was received is still uninvoiced."
                    );
                }

                // ⭐ Ruling P5: and never beyond what arrived on THAT delivery.
                if ($receiptLine !== null) {
                    $onDelivery = PurchaseLineEntry::scaled(ReceiptBilling::billableOn($receiptLine, $line));

                    if ($quantity > $onDelivery) {
                        $challan = $receiptLine->goodsReceipt?->supplier_challan_number;
                        $which = $challan ? "challan {$challan}" : "goods receipt #{$receiptLine->goods_receipt_id}";

                        throw new InvalidArgumentException(
                            "Cannot bill {$entry['quantity']} of ".($line->product?->name ?? "line #{$line->id}")." on {$which}: only {$this->unscaled($onDelivery)} of that delivery is still unbilled."
                        );
                    }
                }

                // ⚠ Through Money, rounded once per line, exactly as the
                // payment and credit note actions already do. Summing floats and
                // rounding at the end gives a different answer from rounding
                // each line, and the bill total has to equal what the journal
                // credits to Accounts Payable to the paisa.
                // ⭐ 17 Oct 2026: a bill line typed as a TOTAL keeps that total
                // to the paisa and shows a six-place rate; typed as a rate it is
                // quantity x rate as it always was (PurchaseLineEntry).
                $typed = PurchaseLineEntry::resolve(
                    (string) $entry['quantity'],
                    isset($entry['unitCost']) ? (string) $entry['unitCost'] : null,
                    isset($entry['lineTotal']) ? (string) $entry['lineTotal'] : null,
                    $entry['entryMode'] ?? null,
                );
                $lineTotal = $typed['line_total'];

                // ⭐⭐ VAT ON A PURCHASE (plan D7). `line_total` stays what was
                // typed. The split decides how much of it is the goods and how
                // much is VAT; `tax_recoverable` decides where that VAT goes:
                // claimable VAT to Input VAT Receivable with stock carried NET,
                // anything else left inside the cost of what was bought.
                // ⚠ Nothing here runs while the business charges no VAT, and the
                // bill is then exactly the bill it always was.
                $split = TaxSplit::untaxed($lineTotal);
                $classId = null;
                $recoverable = false;

                if ($taxed) {
                    $classId = $line->product?->tax_class_id;
                    $rate = isset($entry['taxRate']) && $entry['taxRate'] !== ''
                        ? (string) $entry['taxRate']
                        : $this->tax->rateFor($business, $classId, $date);

                    $split = $this->tax->split($lineTotal, $rate, $pricesIncludeTax);
                    $recoverable = ! $split->tax->isZero() && (bool) ($entry['taxRecoverable'] ?? true);
                }

                // What the supplier is owed for this line, VAT and all.
                $lineTotals[] = $split->gross;
                $priceLines[] = [
                    'label' => $line->product?->name ?? "Line #{$line->id}",
                    'billed' => Money::ofMinor($split->gross->minor - $split->tax->minor),
                    'ordered' => $line->valueOf((string) $entry['quantity']),
                ];
                $taxTotal = $taxTotal->plus($split->tax);

                $bill->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'goods_receipt_line_id' => $receiptLine?->id,
                    'line_kind' => SupplierBillLine::KIND_GOODS,
                    'quantity' => $entry['quantity'],
                    'unit_cost' => $typed['unit_cost'],
                    'line_total' => $lineTotal->toTaka(),
                    'entry_mode' => $typed['entry_mode'],
                    'tax_class_id' => $classId,
                    'tax_rate' => $split->rate,
                    'tax_amount' => $split->tax->toTaka(),
                    'prices_include_tax' => $taxed && $pricesIncludeTax,
                    'tax_recoverable' => $recoverable,
                ]);

                $line->update([
                    'quantity_invoiced' => $this->unscaled($alreadyInvoiced + $quantity),
                ]);
            }

            // ── Ruling P5: non-PO lines (services, utilities, repairs) ─────────
            // Dr the chosen expense head / Cr Accounts Payable through the
            // bill's own journal (PostPurchaseJournals::forBill). No order line,
            // so GRNI and the three-way match never see them.
            foreach ($expenseLines as $entry) {
                $amount = Money::ofTaka((string) $entry['amount']);
                $lineTotals[] = $amount;

                $bill->lines()->create([
                    'line_kind' => SupplierBillLine::KIND_EXPENSE,
                    'expense_account_id' => $entry['account']->id,
                    'description' => $entry['description'] ?? null,
                    'quantity' => '1',
                    'unit_cost' => $amount->toTaka(),
                    'line_total' => $amount->toTaka(),
                    'entry_mode' => 'total',
                    'tax_rate' => '0',
                    'tax_amount' => '0',
                    'prices_include_tax' => false,
                    'tax_recoverable' => false,
                ]);
            }
            // ── end non-PO lines ──────────────────────────────────────────────

            // ⚠ `total` is what the supplier is OWED: the goods plus any VAT,
            // whichever way round the costs were typed.
            $bill->forceFill([
                'total' => Money::sum($lineTotals)->toTaka(),
                'tax_total' => $taxTotal->toTaka(),
                'tax_point_date' => $date,
                'prices_include_tax' => $taxed && $pricesIncludeTax,
            ])->save();

            // ── Ruling P5: likely duplicates (SupplierBillDuplicates) ─────────
            // Asked once the total is known; a refusal rolls the bill back.
            $matches = $warnOnLikelyDuplicates
                ? SupplierBillDuplicates::find($supplier, $billNumber, $date, Money::ofTaka((string) $bill->total), $bill->id)
                : [];

            if ($matches !== []) {
                if (trim((string) $duplicateReason) === '') {
                    throw new LikelyDuplicateBill(SupplierBillDuplicates::sentence($supplier, $matches), $matches);
                }

                $bill->forceFill(['duplicate_override_reason' => trim((string) $duplicateReason), 'duplicate_matches' => $matches])->save();
            }
            // ── end duplicates ───────────────────────────────────────────────

            // ── Purchasing approvals (ruling P7, 18 Oct 2026) ─────────────────
            // Asked once the bill's figures are known and BEFORE anything posts:
            // a bill that needs a second person is refused here, this whole
            // transaction rolls back, and the caller saves it as pending.
            // ⭐ Ruling P5: service lines are no part of the goods a discount
            // is measured against, and each one is a non-PO line.
            $services = Money::sum(array_map(fn (array $entry) => Money::ofTaka((string) $entry['amount']), $expenseLines));
            $goods = Money::ofTaka((string) $bill->total)->minor - $taxTotal->minor - $services->minor;
            $trade = $tradeDiscount === null ? Money::zero() : $this->tradeDiscountPlan($bill->fresh('lines'), $tradeDiscount)['amount'];
            $owed = Money::ofMinor(Money::ofTaka((string) $bill->total)->minor - $trade->minor);
            $basis = $approvalBasis !== null && $approvalBasis->minor > $owed->minor ? $approvalBasis : $owed;

            $this->approvals->enforce(new PurchaseDraft(
                kind: 'bill',
                supplier: $supplier,
                amount: $basis,
                documentDate: $date,
                reference: $billNumber,
                summary: "Bill {$billNumber} from {$supplier->name}".($purchaseOrder ? " on {$purchaseOrder->po_number}" : '')
                    .($basis !== $owed ? ", part of a purchase of {$basis->format()} with its extra costs" : ''),
                payload: [
                    'supplier_id' => $supplier->id,
                    'bill_number' => $billNumber,
                    'bill_date' => $date->toDateString(),
                    'purchase_order_id' => $purchaseOrder?->id,
                    'due_date' => $dueDate ? Carbon::parse($dueDate)->toDateString() : null,
                    // Ruling P6: the terms the bill states and the reason for
                    // an override, so the approved bill keeps the same due date.
                    'terms' => $terms === null ? null : ['type' => $terms->type->value, 'days' => $terms->days],
                    'due_date_reason' => $dueDateReason,
                    'note' => $note,
                    'prices_include_tax' => $pricesIncludeTax,
                    'trade_discount' => $tradeDiscount,
                    'lines' => array_map(fn (array $entry) => [
                        'purchase_order_line_id' => $entry['line']->id,
                        // Ruling P5: the delivery the line bills.
                        'goods_receipt_line_id' => isset($entry['receiptLine']) ? ($entry['receiptLine'] instanceof GoodsReceiptLine ? $entry['receiptLine']->id : (int) $entry['receiptLine']) : null,
                        'quantity' => (string) $entry['quantity'],
                        'unitCost' => isset($entry['unitCost']) ? (string) $entry['unitCost'] : null,
                        'lineTotal' => isset($entry['lineTotal']) ? (string) $entry['lineTotal'] : null,
                        'entryMode' => $entry['entryMode'] ?? null,
                        'taxRate' => isset($entry['taxRate']) ? (string) $entry['taxRate'] : null,
                        'taxRecoverable' => isset($entry['taxRecoverable']) ? (bool) $entry['taxRecoverable'] : null,
                    ], $lines),
                    // Ruling P5: service lines, carriage, and the answer to the
                    // duplicate question, all replayed on approval.
                    'services' => array_map(fn (array $entry) => [
                        'expense_account_id' => $entry['account']->id,
                        'description' => $entry['description'],
                        'amount' => (string) $entry['amount'],
                    ], $expenseLines),
                    'carriage' => array_values($carriageLines),
                    'duplicate_reason' => $duplicateReason,
                ],
                nonPoLines: count($expenseLines),
                priceLines: $priceLines,
                discount: $trade,
                discountBase: Money::ofMinor($goods),
                inProgressId: $bill->id,
            ), $createdBy);
            // ── end purchasing approvals ─────────────────────────────────────

            $this->posting->forBill($bill->fresh(['lines', 'supplier']), $createdBy);

            // ── Ruling P5: carriage through the landed-cost engine ─────────────
            if ($carriageLines !== []) {
                $this->carriage->handle($bill->fresh('supplier'), $carriageLines, $createdBy);
            }

            // ── Supplier discounts (ruling P3, 18 Oct 2026) ──────────────────
            // The bill posts its gross goods value against GRNI exactly as
            // before; the trade discount is its own document and journal that
            // takes the cost off the batches (RecordSupplierDiscount). Same
            // transaction: a discount that cannot find its goods refuses the
            // whole bill, never a bill without its discount.
            if ($tradeDiscount !== null) {
                $this->recordTradeDiscount($bill->fresh(['lines', 'supplier']), $tradeDiscount, $createdBy);
            }
            // ── end supplier discounts ───────────────────────────────────────

            return $bill->fresh('lines');
        });
    }

    /**
     * ⭐ Supplier discounts (ruling P3, 18 Oct 2026): the typed discount
     * turned into paisa (a percentage of the bill's goods, rounded half up, or
     * one amount per line), then recorded.
     *
     * @param  array{mode: string, value?: ?string, reference: string, reason?: ?string, lines?: array<int, string>}  $discount
     */
    private function recordTradeDiscount(SupplierBill $bill, array $discount, User $by): void
    {
        ['amount' => $amount, 'percent' => $percent, 'perLine' => $perLine] = $this->tradeDiscountPlan($bill, $discount);

        if ($amount->isZero()) {
            return;
        }

        $this->discounts->trade($bill, $amount, (string) ($discount['reference'] ?? ''), $by, $discount['mode'], $percent, $perLine, $discount['reason'] ?? null);
    }

    /**
     * The typed trade discount in paisa (split out for the approval gate,
     * ruling P7, which needs the amount before anything posts).
     *
     * @param  array{mode: string, value?: ?string, reference: string, reason?: ?string, lines?: array<int, string>}  $discount
     * @return array{amount: Money, percent: ?string, perLine: ?array<int, int>}
     */
    private function tradeDiscountPlan(SupplierBill $bill, array $discount): array
    {
        $mode = $discount['mode'];
        $percent = null;
        $perLine = null;

        if ($mode === 'lines') {
            $perLine = [];

            // ⭐ Ruling P5: an order line billed on two deliveries carries its
            // discount once, on its first bill line.
            $seen = [];

            foreach ($bill->lines as $line) {
                if ($line->purchase_order_line_id === null || isset($seen[$line->purchase_order_line_id])) {
                    continue;
                }

                $seen[$line->purchase_order_line_id] = true;
                $typed = Money::ofTaka((string) ($discount['lines'][$line->purchase_order_line_id] ?? '0'));

                if ($typed->minor > 0) {
                    $perLine[$line->id] = $typed->minor;
                }
            }

            $amount = Money::ofMinor(array_sum($perLine));
        } elseif ($mode === 'percent') {
            $percent = (string) ($discount['value'] ?? '0');

            if ((float) $percent <= 0 || (float) $percent >= 100) {
                throw new InvalidArgumentException('A discount percentage has to be more than 0 and less than 100.');
            }

            // Of the goods, VAT left out (zero while VAT is off).
            // ⭐ Ruling P5: of the GOODS lines only; a service line is no part of it.
            $services = Money::sum($bill->lines->filter(fn (SupplierBillLine $line) => $line->isExpense())->map(fn (SupplierBillLine $line) => Money::ofTaka((string) $line->line_total)));
            $goods = Money::ofTaka((string) $bill->total)->minor - Money::ofTaka((string) ($bill->tax_total ?? '0'))->minor - $services->minor;
            $amount = Money::ofMinor((int) round($goods * (float) $percent / 100));
        } else {
            $amount = Money::ofTaka((string) ($discount['value'] ?? '0'));
        }

        return ['amount' => $amount, 'percent' => $percent, 'perLine' => $perLine];
    }

    /**
     * ⭐ Ruling P5: the delivery a goods line names, if any, re-read and
     * checked to be a receipt of THIS order line.
     *
     * @param  array<string, mixed>  $entry
     */
    private function checkedReceiptLine(array $entry, PurchaseOrderLine $line): ?GoodsReceiptLine
    {
        $given = $entry['receiptLine'] ?? null;

        if ($given === null) {
            return null;
        }

        $receiptLine = GoodsReceiptLine::query()->with('goodsReceipt:id,supplier_challan_number')->find($given instanceof GoodsReceiptLine ? $given->id : (int) $given);

        if ($receiptLine === null || (int) $receiptLine->purchase_order_line_id !== (int) $line->id) {
            throw new InvalidArgumentException("That delivery line is not a receipt of line #{$line->id}. Pick the goods from the delivery they came on.");
        }

        return $receiptLine;
    }

    /**
     * ⭐ Ruling P5: a non-PO line may debit only what an expense voucher may:
     * an active, postable EXPENSE head that no other module owns (the same
     * rule, VoucherAccounts::refusal). Never a free hand over the ledger.
     *
     * @param  array<int, array{account: Account, amount: string, description?: ?string}>  $expenseLines
     * @return list<array{account: Account, amount: string, description: string}>
     */
    private function checkedExpenseLines(array $expenseLines, Carbon $date): array
    {
        $checked = [];

        foreach (array_values($expenseLines) as $index => $entry) {
            $amount = Money::ofTaka((string) ($entry['amount'] ?? '0'));

            if ($amount->minor <= 0) {
                throw new InvalidArgumentException('Service line '.($index + 1).': the amount must be above zero.');
            }

            $account = $entry['account'];

            if ($account->type !== AccountType::Expense || ! $account->is_postable || ! $account->is_active) {
                throw new InvalidArgumentException("{$account->label()} cannot be used on a supplier bill: pick an active expense head.");
            }

            if (($refusal = VoucherAccounts::refusal($account, $date)) !== null) {
                throw new InvalidArgumentException("{$account->label()}: {$refusal}");
            }

            if (trim((string) ($entry['description'] ?? '')) === '') {
                throw new InvalidArgumentException('Service line '.($index + 1).': say what the service was for.');
            }

            $checked[] = ['account' => $account, 'amount' => $amount->toTaka(), 'description' => trim((string) $entry['description'])];
        }

        return $checked;
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
