<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
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

        $bills = Money::sum(SupplierBill::query()->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        $payments = Money::sum(SupplierPayment::query()->pluck('amount')->map(
            fn ($amount) => Money::ofTaka((string) $amount),
        ));
        $credits = Money::sum(SupplierCreditNote::query()->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));
        $allocated = Money::sum(
            SupplierPayment::query()->with('allocations')->get()
                ->flatMap->allocations
                ->map(fn ($allocation) => Money::ofTaka((string) $allocation->amount)),
        );

        $derived = $bills->plus($payments->negated())->plus($credits->negated());
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
                [SupplierBill::query()->get(['supplier_id', 'total']), 'supplier_id',
                    fn ($bill) => Money::ofTaka((string) $bill->total)],
                [SupplierPayment::query()->get(['supplier_id', 'amount']), 'supplier_id',
                    fn ($payment) => Money::ofTaka((string) $payment->amount)->negated()],
                [SupplierCreditNote::query()->get(['supplier_id', 'total']), 'supplier_id',
                    fn ($credit) => Money::ofTaka((string) $credit->total)->negated()],
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
        $allocated = Money::sum(
            CustomerReceipt::query()->with('allocations')->get()
                ->flatMap->allocations
                ->map(fn ($allocation) => Money::ofTaka((string) $allocation->amount)),
        );

        $unallocated = $receipts->plus($allocated->negated());

        if (! $unallocated->isZero()) {
            $this->warnings[] = "{$business}: {$unallocated->format()} of customer receipts is not allocated to any invoice - received on account, or an allocation was missed.";
        }

        $this->compare(
            $business,
            'Accounts Receivable',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $invoices->plus($receipts->negated()),
            derivedFrom: 'sales invoices less customer receipts',
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

        $billed = Money::sum(SupplierBill::query()->pluck('total')->map(
            fn ($total) => Money::ofTaka((string) $total),
        ));

        $returned = Money::sum(
            PurchaseReturnLine::query()->with(['stockMovement', 'purchaseOrderLine'])->get()
                ->map(fn ($line) => $this->invoiceableValue(
                    $line->stockMovement,
                    $line->purchaseOrderLine,
                    (string) $line->quantity_returned,
                )),
        );

        // Credit notes credit GRNI back for goods returned after billing.
        $credited = Money::sum(SupplierCreditNote::query()->pluck('total')->map(
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

        $fromMovements = Money::sum(
            StockMovement::query()->get(['direction', 'quantity', 'unit_cost'])
                ->map(function ($movement) {
                    $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

                    return $movement->direction === MovementDirection::In ? $value : $value->negated();
                }),
        );

        $this->compare(
            $business,
            'Stock on Hand',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $fromMovements,
            derivedFrom: 'stock movements',
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

        $this->compare(
            $business,
            'Goods Delivered Not Invoiced',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $delivered->plus($released->negated()),
            derivedFrom: 'deliveries less invoiced cost',
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

        $this->compare(
            $business,
            'Cost of Goods Sold',
            $account->id,
            glBalance: $this->glDebitBalance($account->id),
            derived: $this->totalCostReleased(),
            derivedFrom: 'invoiced cost released',
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
            : Money::ofProduct($quantity, (string) $purchaseOrderLine->unit_cost);
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
