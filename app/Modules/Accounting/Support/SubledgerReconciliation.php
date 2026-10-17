<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Carbon;

/**
 * Proves each subsidiary ledger agrees with its general-ledger control account.
 *
 * `LedgerIntegrity` proves the ledger agrees with ITSELF. This proves it agrees
 * with the OPERATIONAL DOCUMENTS - a different failure class entirely: every
 * journal can balance, the trial balance can net to zero, and Accounts Payable
 * can still be wrong for every supplier. A balanced trial balance does not
 * prove the accounting is correct; this is that principle as a command.
 *
 * ⚠⚠ EVERY DERIVED LEG IS VALUED THE WAY ITS POSTING VALUED IT (C-REC-6) -
 * per document, per movement, through Money - never as an algebraically
 * equivalent SQL aggregate. Rounding order differs between the two, and a
 * reconciliation that manufactures paisa breaks out of its own arithmetic
 * teaches people to ignore it.
 *
 * ⚠ PROBLEMS AND WARNINGS ARE DIFFERENT THINGS, exactly as in LedgerIntegrity.
 * A control account WITH postings that disagrees with its documents is a
 * problem. An account with NO postings behind non-zero documents is a warning:
 * that is the signature of operations predating accounting (the demo stock),
 * and journal lines are immutable so it cannot be a deleted posting (C-REC-3).
 *
 * See RECONCILIATION-ARCHITECTURE.md, C-REC-1..11.
 */
final class SubledgerReconciliation
{
    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $checks = 0;

    /**
     * @return array{problems: list<string>, warnings: list<string>, businesses: int, checks: int}
     */
    public static function reconcileAllBusinesses(): array
    {
        $reconciler = new self;
        $businesses = 0;

        // ⚠⚠ Remember where the caller was, and put them back - the same trap
        // LedgerIntegrity documents. Runs cold: without entering each tenant,
        // BusinessScope compiles `1 = 0` and this examines nothing.
        $callersBusiness = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);
            $businesses++;
            $reconciler->reconcileBusiness($business->name);
        }

        if ($callersBusiness === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($callersBusiness);
        }

        return [
            'problems' => $reconciler->problems,
            'warnings' => $reconciler->warnings,
            'businesses' => $businesses,
            'checks' => $reconciler->checks,
        ];
    }

    /**
     * The same checks, for the business the caller is already standing in - the
     * screen's entry point (C-RSC-1).
     *
     * ⚠ NO TENANT SWITCHING (C-RSC-2). The `business` middleware has entered
     * the tenant before this runs; walking other tenants here would reconcile
     * somebody else's books into a screen scoped to this one. The console
     * command keeps `reconcileAllBusinesses()` for the nightly sweep.
     *
     * @return array{problems: list<string>, warnings: list<string>, checks: int}
     */
    public static function reconcileCurrentBusiness(): array
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        $reconciler = new self;

        if ($business !== null) {
            $reconciler->reconcileBusiness($business->name);
        }

        return [
            'problems' => $reconciler->problems,
            'warnings' => $reconciler->warnings,
            'checks' => $reconciler->checks,
        ];
    }

    private function reconcileBusiness(string $business): void
    {
        $today = Carbon::today();

        // ⚠ Anchor keys gate whole families, exactly as they gate the postings
        // (C-REC-2). No mapping means posting is off by configuration, and
        // reporting an intentionally silent module would flag the intended state.
        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $today) !== null) {
            $this->reconcileAccountsPayable($business, $today);
            $this->reconcileGrni($business, $today);
            $this->reconcileStockOnHand($business, $today);
        }

        if (AccountMapping::forKeyOn('sales.revenue', $today) !== null) {
            $this->reconcileAccountsReceivable($business, $today);
            $this->reconcileGdni($business, $today);
            $this->reconcileCostOfGoodsSold($business, $today);
        }

        if (AccountMapping::forKeyOn('payroll.net_payable', $today) !== null) {
            $this->reconcileEmployeeAdvances($business, $today);
        }

        $this->reconcilePartyLedgers($business);
        $this->reconcileCounterpartyLedgers($business);
    }

    /**
     * R7. Every party-kept control head is fully explained by its parties.
     *
     * PostJournal refuses a new line on 1160 / 2150 / 1170 / 2140 / 2130 that
     * names nobody (26 Sep 2026). Lines from before that rule, or slipped in
     * around the engine, are exactly what would make "Musa Islam's ledger" a
     * lie, so they are reported by head and amount rather than hidden. One
     * check per head that has any posting at all.
     */
    private function reconcilePartyLedgers(string $business): void
    {
        foreach (PartyLedgers::all() as $slug => $class) {
            // The loan heads have their own check below, which says the same
            // thing as a total (reconcileCounterpartyLedgers).
            if ($class === Counterparty::class) {
                continue;
            }

            $account = Account::bySlug($slug);

            if ($account === null || JournalLine::query()->where('account_id', $account->id)->doesntExist()) {
                continue;
            }

            $this->checks++;
            $noun = PartyLedgers::noun($class);
            $morph = (new $class)->getMorphClass();
            $unnamedLines = fn () => JournalLine::query()->where('account_id', $account->id)
                ->where(fn ($q) => $q->whereNull('party_id')->orWhere('party_type', '!=', $morph));

            $unnamedCount = $unnamedLines()->count();

            if ($unnamedCount > 0) {
                $unnamed = Money::ofMinor((int) $unnamedLines()->sum('amount_minor'));
                $this->problems[] = "{$business}: {$account->label()} has {$unnamedCount} posting(s) worth {$unnamed->absolute()->format()} with no {$noun} named, so the {$noun} ledgers do not add up to it.";
            }
        }
    }

    /**
     * R7b. Every loan head is its counterparties, added up (loans plan v2, 14
     * Oct 2026): the ledger balance of 1175's heads, 1270, 2116, 2126 to 2128,
     * 2210, 2220 and 2230 equals the sum of the balances of the counterparties
     * named on their lines, in this business.
     *
     * A difference is a PROBLEM, explained by what makes it: lines that name no
     * counterparty (slipped in around PostJournal) and lines naming one that
     * does not exist. A counterparty whose balance sits on a head it no longer
     * fits (the related-party mark moved, or it was deactivated) is a WARNING:
     * the books still add up, but a report will file it wrongly.
     */
    private function reconcileCounterpartyLedgers(string $business): void
    {
        $morph = (new Counterparty)->getMorphClass();
        $balances = CounterpartyBalances::minor();
        $known = Counterparty::query()->whereIn('id', array_keys($balances))->get()->keyBy('id');

        foreach (CounterpartyBalances::heads() as $slug => $account) {
            if (JournalLine::query()->where('account_id', $account->id)->doesntExist()) {
                continue;
            }

            $this->checks++;

            $ledger = $this->glDebitBalance($account->id);
            $parties = Money::ofMinor(array_sum(array_map(
                fn (int $id) => $known->has($id) ? ($balances[$id][$slug] ?? 0) : 0,
                array_keys($balances),
            )));

            if ($ledger->minor !== $parties->minor) {
                $why = [];
                $unnamed = JournalLine::query()->where('account_id', $account->id)
                    ->where(fn ($q) => $q->whereNull('party_id')->orWhere('party_type', '!=', $morph))->count();

                if ($unnamed > 0) {
                    $why[] = "{$unnamed} posting(s) name no counterparty";
                }

                foreach (array_keys($balances) as $id) {
                    if (! $known->has($id) && ($balances[$id][$slug] ?? 0) !== 0) {
                        $why[] = "counterparty id {$id} does not exist";
                    }
                }

                $gap = Money::ofMinor($ledger->minor - $parties->minor)->absolute()->format();
                $this->problems[] = "{$business}: {$account->label()} is {$this->sided($ledger)} in the ledger but its counterparties add up to {$this->sided($parties)}, out by {$gap}"
                    .($why === [] ? '.' : ' ('.implode('; ', $why).').');
            }

            foreach ($known as $id => $counterparty) {
                $minor = $balances[$id][$slug] ?? 0;

                if ($minor === 0) {
                    continue;
                }

                $amount = Money::ofMinor($minor)->absolute()->format();

                if ($slug === CounterpartyRules::RELATED_ONLY && ! $counterparty->related_party) {
                    $this->warnings[] = "{$business}: {$counterparty->legal_name} holds {$amount} on {$account->label()} but is no longer marked a related party. Move it to 11751 by a journal voucher, or mark it related again.";
                }

                if ($slug === CounterpartyRules::UNRELATED_ONLY && $counterparty->related_party) {
                    $this->warnings[] = "{$business}: {$counterparty->legal_name} holds {$amount} on {$account->label()} but is now marked a related party. Move it to 11752 by a journal voucher.";
                }

                if (! $counterparty->is_active) {
                    $this->warnings[] = "{$business}: {$counterparty->legal_name} is deactivated but still holds {$amount} on {$account->label()}.";
                }
            }
        }
    }

    /** "450,000.00 Cr": a balance with the side it sits on. */
    private function sided(Money $balance): string
    {
        return $balance->absolute()->format().($balance->isCredit() ? ' Cr' : ' Dr');
    }

    /**
     * R1. Accounts Payable: GL credit balance vs supplier bills less payments.
     *
     * Two identities are checked, because they fail for different reasons:
     *   GL AP        =  Σ bill totals − Σ payment amounts   (documents posted)
     *   outstanding  =  Σ bill totals − Σ allocations       (what bills still owe)
     * The gap between them is the UNALLOCATED payment residue - money paid on
     * account and not yet applied to a bill. Named and shown, never a break
     * (C-REC-4).
     */
    private function reconcileAccountsPayable(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('purchasing.accounts_payable', $today)?->account;

        if ($account === null) {
            $this->warnings[] = "{$business}: purchasing.accounts_payable is unmapped while inventory posting is on; AP cannot be reconciled.";

            return;
        }

        // ⚠ A reversed bill's journal is reversed too, so it owes nothing; counting
        // it reported false AP and GRNI breaks (found 3 Oct 2026). Same below.
        $bills = Money::sum(SupplierBill::query()->whereNull('reversed_at')->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        // ⚠ PUR-003 (14 Oct 2026): a reversed payment or credit note is undone
        // in the ledger too, so neither counts. Same below and for GRNI.
        $payments = Money::sum(SupplierPayment::query()->whereNull('reversed_at')->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));
        $credits = Money::sum(SupplierCreditNote::query()->whereNull('reversed_at')->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        $allocated = Money::sum(
            SupplierPayment::query()->whereNull('reversed_at')->with('allocations')->get()
                ->flatMap->allocations
                ->map(fn ($allocation) => Money::ofTaka((string) $allocation->amount)),
        );

        $adjusted = Money::sum(PartyAdjustment::query()->whereNotNull('supplier_id')->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));
        $derived = $bills->plus($payments->negated())->plus($credits->negated())->plus($adjusted->negated());
        $unallocated = $payments->plus($allocated->negated());

        if (! $unallocated->isZero()) {
            $this->warnings[] = "{$business}: {$unallocated->format()} of supplier payments is not allocated to any bill - paid on account, or an allocation was missed.";
        }

        $this->compare(
            $business,
            'Accounts Payable',
            $account->id,
            glBalance: $this->glCreditBalance($account->id),
            derived: $derived,
            derivedFrom: 'supplier bills less supplier payments',
        );

        $this->comparePerParty(
            $business,
            'Accounts Payable',
            $account->id,
            partyClass: Supplier::class,
            creditNormal: true,
            derivedByParty: $this->foldByKey([
                [SupplierBill::query()->whereNull('reversed_at')->get(['supplier_id', 'total']), 'supplier_id',
                    fn ($bill) => Money::ofTaka((string) $bill->total)],
                [SupplierPayment::query()->whereNull('reversed_at')->get(['supplier_id', 'amount']), 'supplier_id',
                    fn ($payment) => Money::ofTaka((string) $payment->amount)->negated()],
                [SupplierCreditNote::query()->whereNull('reversed_at')->get(['supplier_id', 'total']), 'supplier_id',
                    fn ($credit) => Money::ofTaka((string) $credit->total)->negated()],
                [PartyAdjustment::query()->whereNotNull('supplier_id')->get(['supplier_id', 'amount']), 'supplier_id',
                    fn ($adjustment) => Money::ofTaka((string) $adjustment->amount)->negated()],
            ]),
            nameFor: fn (array $ids) => Supplier::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
        );
    }

    /**
     * R2. Accounts Receivable: the mirror of AP, per customer.
     */
    private function reconcileAccountsReceivable(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('sales.accounts_receivable', $today)?->account;

        if ($account === null) {
            $this->warnings[] = "{$business}: sales.accounts_receivable is unmapped while sales posting is on; AR cannot be reconciled.";

            return;
        }

        $invoices = Money::sum(SalesInvoice::query()->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        $receipts = Money::sum(CustomerReceipt::query()->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));
        // Sales returns credit AR; refunds debit it back when money physically
        // goes home. Both are documents, so both are legs here (C-SR).
        $returnCredits = Money::sum(SalesReturn::query()->pluck('credit_total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        $refunds = Money::sum(CustomerRefund::query()->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));
        $allocated = Money::sum(
            CustomerReceipt::query()->with('allocations')->get()
                ->flatMap->allocations
                ->map(fn ($allocation) => Money::ofTaka((string) $allocation->amount)),
        );
        $creditAllocated = Money::sum(
            SalesReturn::query()->with('allocations')->get()
                ->flatMap->allocations
                ->map(fn ($allocation) => Money::ofTaka((string) $allocation->amount)),
        );

        $unallocated = $receipts->plus($allocated->negated());

        if (! $unallocated->isZero()) {
            $this->warnings[] = "{$business}: {$unallocated->format()} of customer receipts is not allocated to any invoice - received on account, or an allocation was missed.";
        }

        // ⚠ A return whose traced invoices were already paid leaves credit the
        // allocation could not absorb: credit-on-account, named exactly as the
        // receipt case is, because the customer's next statement must know.
        $creditUnallocated = $returnCredits->plus($creditAllocated->negated());

        if (! $creditUnallocated->isZero()) {
            $this->warnings[] = "{$business}: {$creditUnallocated->format()} of sales-return credit is not applied to any invoice - credit on account, or a refund is owed.";
        }

        $this->compare(
            $business,
            'Accounts Receivable',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $invoices->plus($receipts->negated())->plus($returnCredits->negated())->plus($refunds)
                ->plus(Money::sum(PartyAdjustment::query()->whereNotNull('customer_id')->pluck('amount')->map(
                    fn ($amount) => Money::ofTaka((string) $amount),
                ))->negated()),
            derivedFrom: 'sales invoices less receipts and return credits, plus refunds',
        );

        $this->comparePerParty(
            $business,
            'Accounts Receivable',
            $account->id,
            partyClass: Customer::class,
            creditNormal: false,
            derivedByParty: $this->foldByKey([
                [SalesInvoice::query()->get(['customer_id', 'total']), 'customer_id',
                    fn ($invoice) => Money::ofTaka((string) $invoice->total)],
                [CustomerReceipt::query()->get(['customer_id', 'amount']), 'customer_id',
                    fn ($receipt) => Money::ofTaka((string) $receipt->amount)->negated()],
                [SalesReturn::query()->get(['customer_id', 'credit_total']), 'customer_id',
                    fn ($return) => Money::ofTaka((string) $return->credit_total)->negated()],
                [CustomerRefund::query()->get(['customer_id', 'amount']), 'customer_id',
                    fn ($refund) => Money::ofTaka((string) $refund->amount)],
                [PartyAdjustment::query()->whereNotNull('customer_id')->get(['customer_id', 'amount']), 'customer_id',
                    fn ($adjustment) => Money::ofTaka((string) $adjustment->amount)->negated()],
            ]),
            nameFor: fn (array $ids) => Customer::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
        );
    }

    /**
     * R4. GRNI: receipts valued as posted, less bills, less returns.
     *
     * ⚠ Each leg reproduces its posting's exact expression (C-REC-6): a receipt
     * line is valued at `quantity_received × PO unit cost` when a PO line
     * exists (what the supplier will invoice) and at the movement value when
     * not; a bill debits GRNI at its full total; a return mirrors the receipt.
     */
    private function reconcileGrni(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('inventory.goods_received_not_invoiced', $today)?->account;

        if ($account === null) {
            $this->warnings[] = "{$business}: inventory.goods_received_not_invoiced is unmapped while inventory posting is on; GRNI cannot be reconciled.";

            return;
        }

        $received = Money::sum(
            GoodsReceiptLine::query()->with(['stockMovement', 'purchaseOrderLine'])->get()
                ->map(fn ($line) => $this->invoiceableValue(
                    $line->stockMovement,
                    $line->purchaseOrderLine,
                    (string) $line->quantity_received,
                )),
        );

        // ⚠⚠ D-SB-2. THE BILL RELIEVES GRNI AT THE RECEIVED VALUE, NOT AT ITS
        // OWN TOTAL, so this must derive on the same basis or every price
        // variance reports a phantom break. Billed quantity times the PURCHASE
        // ORDER's unit cost, exactly what `PostPurchaseJournals::forBill()`
        // debits. The difference between that and the invoice is Purchase Price
        // Variance and has nothing to do with GRNI.
        //
        // ⚠ Found by the full-cycle test: the ledger said GRNI was zero and this
        // derived 1,000, because it was still valuing the bill at what the
        // supplier charged rather than at what arrived.
        $billed = Money::sum(
            SupplierBillLine::query()->whereHas('bill', fn ($bill) => $bill->whereNull('reversed_at'))->with('purchaseOrderLine')->get()
                // 17 Oct 2026: the order line's stored total for the billed
                // quantity, the same figure PostPurchaseJournals relieves.
                ->map(fn ($line) => $line->purchaseOrderLine === null
                    ? Money::zero()
                    : $line->purchaseOrderLine->valueOf((string) $line->quantity)),
        );

        $returned = Money::sum(
            PurchaseReturnLine::query()->with(['stockMovement', 'purchaseOrderLine'])->get()
                ->map(fn ($line) => $this->invoiceableValue(
                    $line->stockMovement,
                    $line->purchaseOrderLine,
                    (string) $line->quantity_returned,
                )),
        );

        // Credit notes credit GRNI back for goods returned after billing.
        $credited = Money::sum(SupplierCreditNote::query()->whereNull('reversed_at')->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));

        $derived = $received->plus($billed->negated())->plus($returned->negated())->plus($credited);

        // A negative GRNI means the business has been billed for more than it
        // has received - possible, legal, and worth a human glance.
        if ($derived->minor < 0) {
            $this->warnings[] = "{$business}: GRNI is negative by {$derived->absolute()->format()} - billed for more than has been received.";
        }

        $this->compare(
            $business,
            'Goods Received Not Invoiced',
            $account->id,
            glBalance: $this->glCreditBalance($account->id),
            derived: $derived,
            derivedFrom: 'goods receipts less bills and returns',
        );
    }

    /**
     * R3. Stock on Hand: GL vs the movements every posting was valued from,
     * then the batch cache vs those same movements.
     *
     * ⚠ GL-vs-movements must be EXACT - postings value from movements, so a
     * difference means a movement posted wrongly or not at all. The batch cache
     * disagreeing with movements is a different, lesser finding: a cache
     * repair, owned by inventory:verify-ledger (C-REC-7).
     */
    private function reconcileStockOnHand(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('inventory.stock_on_hand', $today)?->account;

        if ($account === null) {
            return;
        }

        // ⚠⚠ THROUGH `StockValue`, NOT AN EXPRESSION OF ITS OWN. This used to
        // read `quantity x unit_cost` inline, which values a REVALUATION - a
        // movement written with quantity zero and its money in `value_delta` -
        // at nothing, while the posting engine put the whole of it in the
        // ledger. The GL moved and the figure checking the GL did not, forever.
        //
        // ⚠ `StockValue::columns()` rather than a hand-written list: forgetting
        // `value_delta` in a partial select is silent and reproduces the defect
        // exactly.
        $fromMovements = StockValue::sum(
            StockMovement::query()->get(StockValue::columns()),
        );

        // ⚠⚠ INV-003 (14 Oct 2026): STOCK IN TRANSIT IS STILL OURS. A transfer
        // between two of this business's warehouses writes its OUT legs at
        // dispatch and its IN legs at receipt, and posts no journal: what the
        // business owns does not change. Between the two the movements sum was
        // short by the transfer's value while the ledger was not, and every day
        // in transit read as a Stock on Hand PROBLEM (a real break of the same
        // size was then indistinguishable). The legs of transfers still in
        // transit are added back here; a received transfer nets to zero anyway.
        $inTransit = StockValue::sum(
            StockMovement::query()
                ->whereIn('transfer_group_id', StockTransfer::query()->where('status', TransferStatus::InTransit)->select('transfer_group_id'))
                ->get(StockValue::columns()),
        );

        $this->compare(
            $business,
            'Stock on Hand',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: Money::ofMinor($fromMovements->minor - $inTransit->minor),
            derivedFrom: $inTransit->minor === 0 ? 'stock movements' : 'stock movements and stock in transit',
        );

        // ⚠ ALL batches, deliberately not scopeConsumable: expired and inactive
        // batches still carry ledger value - the GL was never relieved of them.
        $fromBatches = Money::sum(
            StockBatch::query()->get(['current_quantity', 'unit_cost'])
                ->map(fn ($batch) => Money::ofProduct((string) $batch->current_quantity, (string) $batch->unit_cost)),
        );

        if ($fromBatches->minor !== $fromMovements->minor) {
            $difference = Money::ofMinor($fromBatches->minor - $fromMovements->minor);
            $this->warnings[] = "{$business}: the stock batch cache differs from the movements by {$difference->absolute()->format()} - run inventory:verify-ledger.";
        }
    }

    /**
     * R5. GDNI: cost delivered (movement values) less cost released by invoices.
     */
    private function reconcileGdni(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('sales.goods_delivered_not_invoiced', $today)?->account;

        if ($account === null) {
            $this->warnings[] = "{$business}: sales.goods_delivered_not_invoiced is unmapped while sales posting is on; GDNI cannot be reconciled.";

            return;
        }

        $delivered = Money::sum(
            DeliveryLine::query()->with('movements')->get()
                ->flatMap->movements
                ->map(fn ($movement) => Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost)),
        );

        $released = $this->totalCostReleased();

        // Returns relieve the bridge for goods that came back unbilled
        // (C-SR-4), valued exactly as the posting valued them.
        $relievedByReturns = Money::sum(SalesReturnLine::query()->pluck('gdni_relief')->map(
            fn ($relief) => Money::ofTaka((string) $relief),
        ));

        $this->compare(
            $business,
            'Goods Delivered Not Invoiced',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $delivered->plus($released->negated())->plus($relievedByReturns->negated()),
            derivedFrom: 'deliveries less invoiced cost and returned-unbilled relief',
        );
    }

    /**
     * R7. COGS: exactly the cost the invoices released, nothing else - expiry
     * write-offs go to their own account, so this must tie to the paisa.
     */
    private function reconcileCostOfGoodsSold(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('sales.cost_of_goods_sold', $today)?->account;

        if ($account === null) {
            $this->warnings[] = "{$business}: sales.cost_of_goods_sold is unmapped while sales posting is on; COGS cannot be reconciled.";

            return;
        }

        // Returns true COGS back toward the layer-exact cost of what STAYS
        // sold; the relief is stored signed, so subtracting it is exact.
        $cogsRelief = Money::sum(SalesReturnLine::query()->pluck('cogs_relief')->map(
            fn ($relief) => Money::ofTaka((string) $relief),
        ));

        $this->compare(
            $business,
            'Cost of Goods Sold',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $this->totalCostReleased()->plus($cogsRelief->negated()),
            derivedFrom: 'invoiced cost released less return relief',
        );
    }

    /**
     * R6. Employee Advances: GL vs the loan ledger, in total.
     *
     * ⚠ TOTAL ONLY, and that is a documented asymmetry, not an oversight
     * (C-REC-5): disbursements carry the employee as party, but recoveries ride
     * the payroll accrual, which aggregates deductions by account with no
     * party. Per-employee proof goes through loan_ledger_entries → payslip,
     * not through the ledger.
     */
    private function reconcileEmployeeAdvances(string $business, Carbon $today): void
    {
        $account = AccountMapping::forKeyOn('payroll.employee_advances', $today)?->account;

        if ($account === null) {
            return;
        }

        $outstanding = Money::sum(LoanLedgerEntry::query()->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));

        $this->compare(
            $business,
            'Employee Advances',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $outstanding,
            derivedFrom: 'the loan ledger',
        );
    }

    // ─── The comparison machinery ───────────────────────────────────────────

    /**
     * One control-account comparison, classified per C-REC-3.
     */
    private function compare(
        string $business,
        string $label,
        int $accountId,
        Money $glBalance,
        Money $derived,
        string $derivedFrom,
    ): void {
        $this->checks++;

        if ($glBalance->minor === $derived->minor) {
            return;
        }

        $difference = Money::ofMinor($glBalance->minor - $derived->minor)->absolute()->format();

        // No postings at all behind non-zero documents: operations predate
        // accounting. Journal lines are immutable, so this cannot be a deleted
        // posting - but an account WITH postings that disagrees always is.
        if (JournalLine::query()->where('account_id', $accountId)->count() === 0) {
            $this->warnings[] = "{$business}: {$label} has documents worth {$derived->format()} and no postings - operations predate accounting here.";

            return;
        }

        $this->problems[] = "{$business}: {$label} is {$glBalance->format()} in the ledger but {$derived->format()} derived from {$derivedFrom} - out by {$difference}.";
    }

    /**
     * Per-party comparison for the two accounts whose every leg carries a party.
     *
     * ⚠ The TOTAL can agree while two parties are wrong in opposite directions -
     * one supplier overstated, another understated, netting to zero. That is
     * precisely the failure a subsidiary ledger exists to catch, so agreement
     * in total never skips this.
     *
     * @param  array<int, Money>  $derivedByParty
     * @param  callable(array<int, int|string>): array<int, string>  $nameFor
     */
    private function comparePerParty(
        string $business,
        string $label,
        int $accountId,
        string $partyClass,
        bool $creditNormal,
        array $derivedByParty,
        callable $nameFor,
    ): void {
        // ⚠ The same never-posted state compare() classifies as a warning: with
        // zero lines on the account there is no per-party ledger to disagree
        // with, and reporting every party as a break would turn C-REC-3's one
        // calm warning into a page of alarms about the same fact.
        if (JournalLine::query()->where('account_id', $accountId)->doesntExist()) {
            return;
        }

        $morphClass = (new $partyClass)->getMorphClass();

        $glByParty = JournalLine::query()
            ->where('account_id', $accountId)
            ->where('party_type', $morphClass)
            ->groupBy('party_id')
            ->selectRaw('party_id, SUM(amount_minor) as total_minor')
            ->pluck('total_minor', 'party_id')
            ->map(fn ($minor) => $creditNormal ? Money::ofMinor(-(int) $minor) : Money::ofMinor((int) $minor));

        // A line on this account with NO party cannot be attributed to anyone,
        // and silently leaving it out would make every per-party figure wrong
        // by an invisible amount.
        $unattributed = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereNull('party_id')
            ->count();

        if ($unattributed > 0) {
            $this->warnings[] = "{$business}: {$label} has {$unattributed} posting line(s) with no party - per-party figures exclude them.";
        }

        $partyIds = array_unique([...array_keys($glByParty->all()), ...array_keys($derivedByParty)]);

        if ($partyIds === []) {
            return;
        }

        $names = $nameFor($partyIds);

        foreach ($partyIds as $partyId) {
            $gl = $glByParty[$partyId] ?? Money::zero();
            $derived = $derivedByParty[$partyId] ?? Money::zero();

            if ($gl->minor === $derived->minor) {
                continue;
            }

            $name = $names[$partyId] ?? "id {$partyId}";
            $this->problems[] = "{$business}: {$label} for {$name} is {$gl->format()} in the ledger but {$derived->format()} in the documents.";
        }
    }

    // ─── Shared derivations ─────────────────────────────────────────────────

    /**
     * What the supplier will invoice for a receipt or return line - the exact
     * expression PostPurchaseJournals uses, including its skip conditions.
     */
    private function invoiceableValue(?StockMovement $movement, ?object $purchaseOrderLine, string $quantity): Money
    {
        if ($movement === null) {
            return Money::zero();
        }

        $stockValue = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

        if ($stockValue->isZero()) {
            return Money::zero();
        }

        return $purchaseOrderLine === null
            ? $stockValue
            : $purchaseOrderLine->valueOf($quantity);
    }

    private function totalCostReleased(): Money
    {
        return Money::sum(SalesInvoiceLine::query()->pluck('cost_released')->map(
            fn ($cost) => Money::ofTaka((string) $cost),
        ));
    }

    /**
     * Folds document sets into one per-party Money map. Each set is
     * [iterable documents, key column, value callable] - the callable returns
     * a signed Money, so subtractions are negated values, not a special case.
     *
     * @param  array<int, array{0: iterable<object>, 1: string, 2: callable(object): Money}>  $sets
     * @return array<int, Money>
     */
    private function foldByKey(array $sets): array
    {
        $byParty = [];

        foreach ($sets as [$documents, $key, $value]) {
            foreach ($documents as $document) {
                $partyId = $document->{$key};
                $byParty[$partyId] = ($byParty[$partyId] ?? Money::zero())->plus($value($document));
            }
        }

        return $byParty;
    }

    private function glDebitBalance(int $accountId): Money
    {
        return Money::ofMinor((int) JournalLine::query()->where('account_id', $accountId)->sum('amount_minor'));
    }

    private function glCreditBalance(int $accountId): Money
    {
        return $this->glDebitBalance($accountId)->negated();
    }
}
