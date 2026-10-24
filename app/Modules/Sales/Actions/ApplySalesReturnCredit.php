<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B20 (Joy, 24 Oct 2026): the credit a return left open (the
 * customer had already paid the invoice it came from, and took no cash back)
 * is used against ANOTHER open invoice of the same customer, the next one.
 *
 * ⚠⚠ NO JOURNAL, ON PURPOSE. The return already credited Accounts Receivable
 * with its whole credit when it was recorded; what was left over has been
 * sitting on the customer's account as a credit ever since. Applying it to an
 * invoice only says WHICH bill that credit pays (the bill-wise match), exactly
 * what the return's own allocations do. A second posting would credit the
 * receivable twice.
 *
 * ⚠ SEAM, customer advances (IP3, 2150 Customer Advances): once that branch is
 * in, an open return credit may instead be turned into an advance held for the
 * customer. That is a reclassification (Dr Accounts Receivable, Cr 2150) with
 * its own document; it belongs next to this action, never inside it, and it
 * must spend the same refundable() figure so one taka is used once.
 */
class ApplySalesReturnCredit
{
    /**
     * @param  string|null  $openSeen  the open credit the page showed: a stale page (or a second click) is refused
     *
     * @throws ValidationException
     */
    public function handle(SalesReturn $return, SalesInvoice $invoice, string $amount, ?string $openSeen = null): SalesReturnAllocation
    {
        $wanted = Money::ofTaka($amount);

        if ($wanted->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }

        return DB::transaction(function () use ($return, $invoice, $wanted, $openSeen) {
            $return = SalesReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $invoice = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // A reversed return (B13, another branch) has no credit left to use.
            if ($return->getAttribute('reversed_at') !== null) {
                throw ValidationException::withMessages(['amount' => "{$return->return_number} has been reversed."]);
            }

            if ((int) $invoice->customer_id !== (int) $return->customer_id) {
                throw ValidationException::withMessages(['sales_invoice_id' => "Invoice {$invoice->invoice_number} is another customer's."]);
            }

            if ($invoice->isReversed()) {
                throw ValidationException::withMessages(['sales_invoice_id' => "Invoice {$invoice->invoice_number} has been credited in full."]);
            }

            $open = $return->refundable();

            if ($openSeen !== null && Money::ofTaka($openSeen)->minor !== $open->minor) {
                throw ValidationException::withMessages([
                    'amount' => "The open credit on {$return->return_number} is now ".$open->toTaka().' (it changed since the page opened). Check and try again.',
                ]);
            }

            if ($wanted->minor > $open->minor) {
                throw ValidationException::withMessages(['amount' => "Only {$open->toTaka()} of {$return->return_number} is still open."]);
            }

            $owed = $invoice->outstanding();

            if ($wanted->minor > $owed->minor) {
                throw ValidationException::withMessages(['amount' => "Invoice {$invoice->invoice_number} owes only {$owed->toTaka()}."]);
            }

            return $return->allocations()->create([
                'business_id' => $return->business_id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $wanted->toTaka(),
            ]);
        });
    }
}
