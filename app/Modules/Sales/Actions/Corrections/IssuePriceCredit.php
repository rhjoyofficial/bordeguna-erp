<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Corrections\SalesDependencies;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A price credit note (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2): the
 * customer keeps the goods and pays less. No fake goods return.
 *
 *   Dr 4200 Sales Returns and Allowances / Cr 1140 (customer)
 *
 * The credit note is its own document (CN number, citing the invoice); the
 * journal and the allocation to the invoice ride on a `customer_credit_note`
 * party adjustment it owns, so the ageing, the customer statement and the
 * sub-ledger reconciliation read it like any other reduction of what is owed.
 *
 * Rules: a live invoice only (not credited, not an opening balance, not a staff
 * purchase); the credit date open, not before the invoice, not in the future;
 * no more than the invoice still owes (money already paid is given back by
 * reversing or re-allocating the payment, never by a credit that leaves the
 * invoice owing less than nothing).
 */
class IssuePriceCredit
{
    public function __construct(
        private readonly RecordPartyAdjustment $adjustments,
        private readonly CreditSalesInvoice $credit,
    ) {}

    /**
     * ⭐ 24 Oct 2026: called by the corrections engine only
     * (SalesInvoiceAdditionCorrector, type sales.price_credit), with the
     * Owner's closed-month `$pass` when the credit is dated in a closed month.
     */
    public function handle(SalesInvoice $invoice, mixed $creditDate, string $amount, ActionReason $reason, User $by, ?ClosedPeriodPass $pass = null): SalesCreditNote
    {
        $on = Carbon::parse($creditDate)->startOfDay();
        $money = Money::ofTaka($amount);

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['credit_date' => 'A credit note cannot be dated in the future.']);
        }

        if ($on->lt($invoice->invoice_date->copy()->startOfDay())) {
            throw ValidationException::withMessages(['credit_date' => "A credit note cannot be dated before its invoice ({$invoice->invoice_date->format('d/m/Y')})."]);
        }

        AccountingPeriod::assertOpenFor($on, $pass);

        return DB::transaction(function () use ($invoice, $on, $money, $reason, $by, $pass) {
            $invoice = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $refusal = match (true) {
                $invoice->isReversed() => "Invoice {$invoice->invoice_number} is already credited in full.",
                (bool) $invoice->is_opening => "Invoice {$invoice->invoice_number} is an opening balance; correct it under Accounting, Opening balances.",
                (bool) $invoice->is_staff_sale => "Invoice {$invoice->invoice_number} is a staff purchase, which Staff purchases corrects.",
                $money->minor <= 0 => 'Say how much the price comes down by.',
                $money->minor > $invoice->outstanding()->minor => "Invoice {$invoice->invoice_number} owes only {$invoice->outstanding()->format()}; for money already paid, reverse or re-allocate the payment instead.",
                default => null,
            };

            if ($refusal !== null) {
                throw ValidationException::withMessages(['amount' => $refusal]);
            }

            if (($setOff = SalesDependencies::setOffBlocker([(int) $invoice->id], 'a credit note')) !== null && $setOff->code === 'set_off_waiting') {
                throw ValidationException::withMessages(['amount' => $setOff->message]);
            }

            $note = DocumentNumber::claim('credit_note_number', fn () => $this->credit->nextNumber((int) $invoice->business_id, $on), fn (string $number) => SalesCreditNote::create([
                'business_id' => $invoice->business_id,
                'credit_note_number' => $number,
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'kind' => SalesCreditNote::PRICE,
                'credit_date' => $on->toDateString(),
                'amount' => $money->toTaka(),
                'reason' => $reason->display(),
                'created_by' => $by->id,
            ]));

            $adjustment = $this->adjustments->handle(
                kind: 'customer_credit_note',
                date: $on,
                customer: $invoice->customer()->firstOrFail(),
                supplier: null,
                invoices: [$invoice->id => $money->toTaka()],
                bills: [],
                reason: "Credit note {$note->credit_note_number} on {$invoice->invoice_number}: {$reason->display()}",
                by: $by,
                source: $note,
                pass: $pass,
            );

            $journal = Journal::query()->where('source_type', $note->getMorphClass())->where('source_id', $note->id)->value('id');
            $note->forceFill(['party_adjustment_id' => $adjustment->id, 'journal_id' => $journal])->save();

            CorrectionLog::event('price_credit', $by, $invoice, [
                'credit_note' => $note->credit_note_number,
                'amount' => $money->toTaka(),
                'credit_date' => $on->toDateString(),
                'reason' => $reason->display(),
            ]);

            return $note->fresh();
        });
    }
}
