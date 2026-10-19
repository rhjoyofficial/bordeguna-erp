<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who owes what, and for how long, as at a date.
 *
 * ⚠⚠ BUILT FROM DOCUMENTS, NOT FROM THE LEDGER (C-GLA-9). Only a document has a
 * due date; a journal line does not and never will. The ledger's job here is to
 * CHECK the total (C-GLA-12), which is a different and much better use for it.
 *
 * ⚠⚠ THE MODELS' OWN `outstanding()` IS NOT USED, and must not be (C-GLA-8).
 * `SalesInvoice::outstanding()` sums every allocation ever made, which answers
 * "what is outstanding TODAY". An ageing as at 31 July must not subtract a
 * receipt made on 5 August. Worse, an allocation row carries no date of its own
 * - the date lives on the parent receipt, payment, return or credit note - so
 * the as-at filter has to be applied through the PARENT, which is exactly what
 * this class does and what no model method can do for it.
 *
 * ⚠ Every amount goes through `Money` per document, never as one SQL aggregate,
 * for the same reason `SubledgerReconciliation` does it (C-REC-6): rounding
 * order differs between the two, and an ageing that manufactures paisa breaks
 * out of its own arithmetic teaches people to ignore it.
 *
 * ⚠ Business scoping comes from each model's own global scope. This class never
 * writes `where business_id`.
 */
final class Ageing
{
    /**
     * @param  Collection<int, AgeingRow>  $rows
     */
    private function __construct(
        public readonly string $label,
        public readonly Carbon $asAt,
        public readonly Collection $rows,
        public readonly int $partiesOmitted,
        /** The control account's ledger balance as at the same date, or null if unmapped. */
        public readonly ?Money $controlBalance,
        public readonly ?string $controlAccountLabel,
    ) {}

    /**
     * What customers owe.
     *
     * The identity this produces, and why the tie-out in C-GLA-12 is exact:
     *
     *   Σ invoice outstanding  = Σinvoices − Σreceipt allocations − Σcredit allocations
     *   Σ on account           = Σrefunds − (Σreceipts − Σreceipt allocations)
     *                                     − (Σcredits  − Σcredit allocations)
     *   ─────────────────────────────────────────────────────────────────────
     *   total                  = Σinvoices − Σreceipts − Σcredits + Σrefunds
     *
     * which is character for character the AR derivation `SubledgerReconciliation`
     * already checks against the control account. ⚠ The allocation terms appear
     * twice with opposite signs and cancel, so the total holds WHATEVER as-at
     * filter is applied to allocations: an as-at bug can move money between
     * columns but can never break the total.
     */
    public static function receivables(Carbon $asAt): self
    {
        $on = $asAt->toDateString();

        // ⭐ Corrections wave 1 (20 Oct 2026): an invoice credited in full and a
        // reversed receipt stood until the day of their mirror journals, and
        // only until then (the PUR-003 shape of the payables side below).
        $invoices = SalesInvoice::query()->whereDate('invoice_date', '<=', $on)->standingOn($on)->get();
        $included = $invoices->pluck('id')->flip();

        $receipts = CustomerReceipt::query()->whereDate('received_on', '<=', $on)->standingOn($on)->get();
        $receiptAllocations = self::allocationsFor(
            CustomerReceiptAllocation::query()->whereIn('customer_receipt_id', $receipts->pluck('id')),
            'customer_receipt_id',
            'sales_invoice_id',
            $included,
        );

        $returns = SalesReturn::query()->whereDate('returned_at', '<=', $on)->get();
        $returnAllocations = self::allocationsFor(
            SalesReturnAllocation::query()->whereIn('sales_return_id', $returns->pluck('id')),
            'sales_return_id',
            'sales_invoice_id',
            $included,
        );

        $refunds = CustomerRefund::query()->whereDate('refunded_on', '<=', $on)->get();

        // Write-offs, discounts and set-offs dated on or before the day.
        $invoiceAdjustments = self::allocationsFor(
            PartyAdjustmentAllocation::query()->whereNotNull('sales_invoice_id')
                ->whereIn('party_adjustment_id', PartyAdjustment::query()->whereDate('adjusted_on', '<=', $on)->select('id')),
            'party_adjustment_id',
            'sales_invoice_id',
            $included,
        );

        $documents = self::documentsByParty(
            $invoices,
            'customer_id',
            fn (SalesInvoice $invoice) => new AgeingDocument(
                id: $invoice->id,
                number: $invoice->invoice_number,
                documentDate: $invoice->invoice_date,
                dueDate: $invoice->due_date,
                total: Money::ofTaka((string) $invoice->total),
                outstanding: Money::ofTaka((string) $invoice->total)
                    ->plus(($receiptAllocations['byTarget'][$invoice->id] ?? Money::zero())->negated())
                    ->plus(($returnAllocations['byTarget'][$invoice->id] ?? Money::zero())->negated())
                    ->plus(($invoiceAdjustments['byTarget'][$invoice->id] ?? Money::zero())->negated()),
                daysOverdue: self::daysOverdue($invoice->due_date ?? $invoice->invoice_date, $asAt),
            ),
        );

        // ⚠⚠ C-GLA-11. Money with no document behind it. A receipt on account
        // reduces what the customer owes and sits in no age bucket; a refund
        // pushes the other way. Leaving either out is the standard ageing
        // defect, and it shows up as a total that does not equal the balance
        // sheet - which is the one place nobody looks for a report bug.
        $onAccount = self::foldByParty([
            [$refunds, 'customer_id', fn (CustomerRefund $refund) => Money::ofTaka((string) $refund->amount)],
            [$receipts, 'customer_id', fn (CustomerReceipt $receipt) => Money::ofTaka((string) $receipt->amount)
                ->plus(($receiptAllocations['bySource'][$receipt->id] ?? Money::zero())->negated())
                ->negated()],
            [$returns, 'customer_id', fn (SalesReturn $return) => Money::ofTaka((string) $return->credit_total)
                ->plus(($returnAllocations['bySource'][$return->id] ?? Money::zero())->negated())
                ->negated()],
        ]);

        return self::assemble(
            label: 'Accounts Receivable',
            asAt: $asAt,
            documents: $documents,
            onAccount: $onAccount,
            names: fn (array $ids) => Customer::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            mappingKey: 'sales.accounts_receivable',
            creditNormal: false,
        );
    }

    /**
     * What the business owes suppliers. The mirror of `receivables()`, with one
     * fewer leg: there is no supplier-side refund document.
     */
    public static function payables(Carbon $asAt): self
    {
        $on = $asAt->toDateString();

        // ⚠ A reversed bill is not owed from the day it was reversed, and only
        // from then: the reversal journal is dated that day (ReverseSupplierBill),
        // so an ageing as at an earlier date still shows the bill, exactly as the
        // control account did on that date. Nothing has to be netted against it,
        // because a paid or credited bill cannot be reversed at all.
        $bills = SupplierBill::query()
            ->whereDate('bill_date', '<=', $on)
            ->where(fn (Builder $bill) => $bill->whereNull('reversed_at')->orWhereDate('reversed_at', '>', $on))
            ->get();
        $included = $bills->pluck('id')->flip();

        // ⚠ PUR-003 (14 Oct 2026): a reversed payment or credit note stood
        // until the day it was reversed, as its journal did. Its allocations
        // are released on reversal, so on an earlier date it counts as paid on
        // account: the supplier's total still ties to the control account.
        $payments = SupplierPayment::query()->whereDate('paid_on', '<=', $on)->standingOn($on)->get();
        $paymentAllocations = self::allocationsFor(
            SupplierPaymentAllocation::query()->whereIn('supplier_payment_id', $payments->pluck('id')),
            'supplier_payment_id',
            'supplier_bill_id',
            $included,
        );

        $credits = SupplierCreditNote::query()->whereDate('credit_date', '<=', $on)->standingOn($on)->get();
        $creditAllocations = self::allocationsFor(
            SupplierCreditNoteAllocation::query()->whereIn('supplier_credit_note_id', $credits->pluck('id')),
            'supplier_credit_note_id',
            'supplier_bill_id',
            $included,
        );

        $billAdjustments = self::allocationsFor(
            PartyAdjustmentAllocation::query()->whereNotNull('supplier_bill_id')
                ->whereIn('party_adjustment_id', PartyAdjustment::query()->whereDate('adjusted_on', '<=', $on)->select('id')),
            'party_adjustment_id',
            'supplier_bill_id',
            $included,
        );

        $documents = self::documentsByParty(
            $bills,
            'supplier_id',
            fn (SupplierBill $bill) => new AgeingDocument(
                id: $bill->id,
                number: $bill->bill_number,
                documentDate: $bill->bill_date,
                dueDate: $bill->due_date,
                total: Money::ofTaka((string) $bill->total),
                outstanding: Money::ofTaka((string) $bill->total)
                    ->plus(($paymentAllocations['byTarget'][$bill->id] ?? Money::zero())->negated())
                    ->plus(($creditAllocations['byTarget'][$bill->id] ?? Money::zero())->negated())
                    ->plus(($billAdjustments['byTarget'][$bill->id] ?? Money::zero())->negated()),
                daysOverdue: self::daysOverdue($bill->due_date ?? $bill->bill_date, $asAt),
            ),
        );

        $onAccount = self::foldByParty([
            [$payments, 'supplier_id', fn (SupplierPayment $payment) => Money::ofTaka((string) $payment->amount)
                ->plus(($paymentAllocations['bySource'][$payment->id] ?? Money::zero())->negated())
                ->negated()],
            [$credits, 'supplier_id', fn (SupplierCreditNote $credit) => Money::ofTaka((string) $credit->total)
                ->plus(($creditAllocations['bySource'][$credit->id] ?? Money::zero())->negated())
                ->negated()],
        ]);

        return self::assemble(
            label: 'Accounts Payable',
            asAt: $asAt,
            documents: $documents,
            onAccount: $onAccount,
            names: fn (array $ids) => Supplier::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            mappingKey: 'purchasing.accounts_payable',
            creditNormal: true,
        );
    }

    // ─── Totals and the tie-out ─────────────────────────────────────────────

    public function bucket(AgeingBucket $bucket): Money
    {
        return Money::sum($this->rows->map(fn (AgeingRow $row) => $row->bucket($bucket)));
    }

    public function onAccount(): Money
    {
        return Money::sum($this->rows->map(fn (AgeingRow $row) => $row->onAccount));
    }

    public function total(): Money
    {
        return Money::sum($this->rows->map(fn (AgeingRow $row) => $row->total()));
    }

    /**
     * The parties on the report's own side: customers who owe us, or suppliers
     * we owe. The sum of every row whose total is positive.
     *
     * ⚠ Presentation only (F33, practice run 17 Oct 2026). `total()` stays the
     * figure that ties to the control account; it is this plus the negative of
     * `totalInCredit()`. Splitting it keeps a customer we owe after a return
     * from showing as "-807.50 owed to us".
     */
    public function totalOwing(): Money
    {
        return Money::sum($this->rows->map(fn (AgeingRow $row) => $row->total())->filter(fn (Money $total) => $total->isDebit()));
    }

    /**
     * The parties the other way round, presented positive: a customer whose
     * return credit we have not refunded or applied (we owe them), a supplier
     * paid in advance (they owe us).
     */
    public function totalInCredit(): Money
    {
        return Money::sum($this->rows->map(fn (AgeingRow $row) => $row->total())->filter(fn (Money $total) => $total->isCredit()))->negated();
    }

    /**
     * ⚠⚠ C-GLA-12. The tie-out is shown on the screen, not left to a nightly
     * command nobody runs.
     *
     * ⚠ It assumes each posting's `journal_date` equals its document date. If
     * those ever diverge this reports a disagreement, which is A FINDING, not a
     * bug in the report - and is exactly the kind of thing nobody would
     * otherwise notice for a quarter.
     */
    public function tiesOut(): bool
    {
        return $this->controlBalance === null || $this->controlBalance->minor === $this->total()->minor;
    }

    public function difference(): Money
    {
        return $this->controlBalance === null
            ? Money::zero()
            : Money::ofMinor($this->controlBalance->minor - $this->total()->minor);
    }

    /**
     * True when any shown document fell back to its document date for the
     * ageing basis (C-GLA-10), so the screen can say the rule out loud only
     * when it actually bit.
     */
    public function usesFallbackDates(): bool
    {
        return $this->rows->contains(
            fn (AgeingRow $row) => $row->documents->contains(
                fn (AgeingDocument $document) => $document->usesFallbackDate()
            )
        );
    }

    // ─── Assembly ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, Collection<int, AgeingDocument>>  $documents
     * @param  array<int, Money>  $onAccount
     * @param  callable(array<int, int>): array<int, string>  $names
     */
    private static function assemble(
        string $label,
        Carbon $asAt,
        array $documents,
        array $onAccount,
        callable $names,
        string $mappingKey,
        bool $creditNormal,
    ): self {
        $partyIds = array_values(array_unique([...array_keys($documents), ...array_keys($onAccount)]));
        $partyNames = $partyIds === [] ? [] : $names($partyIds);

        $all = collect($partyIds)->map(fn (int $partyId) => new AgeingRow(
            partyId: $partyId,
            partyName: $partyNames[$partyId] ?? "id {$partyId}",
            documents: ($documents[$partyId] ?? collect())
                // ⚠ C-GLA-13. A settled document is dropped; an OVER-allocated
                // one is kept, negative. Hiding it would break the identity
                // above and hide a real data problem behind a tidy report.
                ->reject(fn (AgeingDocument $document) => $document->outstanding->isZero())
                ->sortBy(fn (AgeingDocument $document) => $document->basisDate()->toDateString())
                ->values(),
            onAccount: $onAccount[$partyId] ?? Money::zero(),
        ));

        $rows = $all->filter(fn (AgeingRow $row) => $row->isWorthShowing())
            ->sortByDesc(fn (AgeingRow $row) => $row->total()->minor)
            ->values();

        $account = AccountMapping::forKeyOn($mappingKey, $asAt)?->account;

        return new self(
            label: $label,
            asAt: $asAt,
            rows: $rows,
            partiesOmitted: $all->count() - $rows->count(),
            controlBalance: $account === null ? null : self::controlBalance($account->id, $asAt, $creditNormal),
            controlAccountLabel: $account?->label(),
        );
    }

    private static function controlBalance(int $accountId, Carbon $asAt, bool $creditNormal): Money
    {
        $signed = Money::ofMinor((int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereDate('journals.journal_date', '<=', $asAt->toDateString())
            ->sum('journal_lines.amount_minor'));

        return $creditNormal ? $signed->negated() : $signed;
    }

    /**
     * Groups documents by their party, building each one through the callable.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): AgeingDocument  $build
     * @return array<int, Collection<int, AgeingDocument>>
     */
    private static function documentsByParty(Collection $models, string $partyKey, callable $build): array
    {
        return $models
            ->groupBy(fn ($model) => (int) $model->{$partyKey})
            ->map(fn (Collection $group) => $group->map($build)->values())
            ->all();
    }

    /**
     * Sums allocations two ways at once: per TARGET document (what the invoice
     * has had knocked off it) and per SOURCE document (how much of the receipt
     * has been applied).
     *
     * ⚠⚠ An allocation counts only if its target document is itself included as
     * at the date. A receipt allocated to an invoice raised AFTER the as-at date
     * is a prepayment on that date, and dropping the allocation is what makes it
     * appear correctly as money on account rather than as a reduction of an
     * invoice that did not exist yet.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  Collection<int|string, int>  $includedTargets
     * @return array{bySource: array<int, Money>, byTarget: array<int, Money>}
     */
    private static function allocationsFor(
        $query,
        string $sourceKey,
        string $targetKey,
        Collection $includedTargets,
    ): array {
        $bySource = [];
        $byTarget = [];

        foreach ($query->get() as $allocation) {
            $targetId = (int) $allocation->{$targetKey};

            if (! $includedTargets->has($targetId)) {
                continue;
            }

            $sourceId = (int) $allocation->{$sourceKey};
            $amount = Money::ofTaka((string) $allocation->amount);

            $bySource[$sourceId] = ($bySource[$sourceId] ?? Money::zero())->plus($amount);
            $byTarget[$targetId] = ($byTarget[$targetId] ?? Money::zero())->plus($amount);
        }

        return ['bySource' => $bySource, 'byTarget' => $byTarget];
    }

    /**
     * Folds document sets into one signed per-party map, exactly as
     * `SubledgerReconciliation::foldByKey()` does. Subtractions are negated
     * values rather than a special case.
     *
     * @param  array<int, array{0: Collection<int, Model>, 1: string, 2: callable(Model): Money}>  $sets
     * @return array<int, Money>
     */
    private static function foldByParty(array $sets): array
    {
        $byParty = [];

        foreach ($sets as [$models, $key, $value]) {
            foreach ($models as $model) {
                $partyId = (int) $model->{$key};
                $byParty[$partyId] = ($byParty[$partyId] ?? Money::zero())->plus($value($model));
            }
        }

        return $byParty;
    }

    /**
     * ⚠ Both sides reduced to a DATE STRING and re-parsed before the difference
     * is taken.
     *
     * A model date cast hands back a Carbon at midnight in the app timezone,
     * while the as-at date arrives from a request; comparing them directly makes
     * the answer depend on a time component nobody typed. This project has paid
     * for a date-cast comparison five separate times, and an off-by-one here
     * moves a document into the wrong bucket without changing a single total,
     * which is the hardest kind of wrong to notice.
     */
    private static function daysOverdue(Carbon $basis, Carbon $asAt): int
    {
        return (int) Carbon::parse($basis->toDateString())
            ->diffInDays(Carbon::parse($asAt->toDateString()), false);
    }
}
