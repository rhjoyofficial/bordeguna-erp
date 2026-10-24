<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ An extra charge on an issued invoice (corrections wave 1, 20 Oct 2026,
 * FINAL-PLAN 3.2 "Extra charge (debit note)"): the customer owes more than the
 * invoice billed (a price typed too low, a charge left off).
 *
 * The issued invoice never changes. The extra charge is a SUPPLEMENTARY
 * INVOICE of its own (its own INV number, `debit_note_for_invoice_id` naming
 * the invoice, one line "Extra charge on INV-...: ..."), raised through
 * RecordSalesInvoice, so it posts Dr 1140 / Cr 4101 Product Sales like any
 * invoice, is paid by an ordinary receipt, and every report, statement and
 * ageing already reads it.
 */
class RaiseExtraCharge
{
    public function __construct(private readonly RecordSalesInvoice $record) {}

    /**
     * ⭐ 24 Oct 2026: called by the corrections engine only
     * (SalesInvoiceAdditionCorrector, type sales.extra_charge), with the
     * Owner's closed-month `$pass` when the charge is dated in a closed month.
     */
    public function handle(SalesInvoice $invoice, mixed $chargeDate, string $amount, string $description, ActionReason $reason, User $by, mixed $dueDate = null, ?ClosedPeriodPass $pass = null): SalesInvoice
    {
        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Say how much more the customer owes.']);
        }

        return DB::transaction(function () use ($invoice, $chargeDate, $money, $description, $reason, $by, $dueDate, $pass) {
            $invoice = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $refusal = match (true) {
                $invoice->isReversed() => "Invoice {$invoice->invoice_number} is credited in full; raise a new invoice instead.",
                (bool) $invoice->is_opening => "Invoice {$invoice->invoice_number} is an opening balance; correct it under Accounting, Opening balances.",
                (bool) $invoice->is_staff_sale => "Invoice {$invoice->invoice_number} is a staff purchase, which Staff purchases corrects.",
                $invoice->debit_note_for_invoice_id !== null => "Invoice {$invoice->invoice_number} is itself an extra charge; raise it on the first invoice.",
                default => null,
            };

            if ($refusal !== null) {
                throw ValidationException::withMessages(['amount' => $refusal]);
            }

            if (Carbon::parse($chargeDate)->startOfDay()->lt($invoice->invoice_date->copy()->startOfDay())) {
                throw ValidationException::withMessages(['charge_date' => "An extra charge cannot be dated before its invoice ({$invoice->invoice_date->format('d/m/Y')})."]);
            }

            $extra = $this->record->handle(
                customer: $invoice->customer()->firstOrFail(),
                lines: [[
                    'product' => null,
                    'description' => mb_substr("Extra charge on {$invoice->invoice_number}: ".trim($description), 0, 255),
                    'quantity' => '1',
                    'unitPrice' => $money->toTaka(),
                ]],
                createdBy: $by,
                invoiceDate: $chargeDate,
                dueDate: $dueDate,
                note: $reason->display(),
                pass: $pass,
                debitNoteFor: $invoice,
            );

            CorrectionLog::event('extra_charge', $by, $invoice, [
                'extra_charge' => $extra->invoice_number,
                'amount' => $money->toTaka(),
                'reason' => $reason->display(),
            ]);

            return $extra;
        });
    }
}
