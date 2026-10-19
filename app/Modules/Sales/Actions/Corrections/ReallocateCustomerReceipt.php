<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\SalesDependencies;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Re-allocate (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2 and 4.1):
 * money a receipt put on the wrong invoice moves to another invoice of the
 * SAME customer. No journal: the receipt and both invoices sit in 1140 for the
 * same customer, so only the documents' "what is paid" changes.
 *
 * Refused: a reversed receipt; one a voucher made or payroll settled (their
 * own documents own the allocation); moving more than is on the first invoice
 * or more than the second still owes; another customer's invoice; a credited
 * invoice; and either invoice in a set-off (SalesDependencies). Logged in the
 * corrections log, so the History and the Corrections report show it.
 */
class ReallocateCustomerReceipt
{
    public function handle(CustomerReceipt $receipt, SalesInvoice $from, SalesInvoice $to, string $amount, ActionReason $reason, User $by): CustomerReceipt
    {
        $money = Money::ofTaka($amount);
        $label = CustomerReceiptActionPolicy::label($receipt);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Say how much moves.']);
        }

        return DB::transaction(function () use ($receipt, $from, $to, $money, $reason, $by, $label) {
            $receipt = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $invoices = SalesInvoice::query()->whereIn('id', [$from->id, $to->id])->lockForUpdate()->get()->keyBy('id');
            $from = $invoices[$from->id];
            $to = $invoices[$to->id];

            $refusal = match (true) {
                $receipt->isReversed() => "{$label} is reversed; there is nothing to move.",
                $receipt->voucher_id !== null => "{$label} was made by a voucher; correct the voucher instead.",
                ! $receipt->isCash() => "{$label} settled a staff purchase through payroll; its run owns it.",
                (int) $from->id === (int) $to->id => 'Choose a different invoice to move the money to.',
                (int) $to->customer_id !== (int) $receipt->customer_id => "Invoice {$to->invoice_number} is another customer's; money moves only between one customer's invoices.",
                $to->isReversed() => "Invoice {$to->invoice_number} is credited in full and owes nothing.",
                default => null,
            };

            if ($refusal !== null) {
                throw ValidationException::withMessages(['to_invoice_id' => $refusal]);
            }

            if (($setOff = SalesDependencies::setOffBlocker([(int) $from->id, (int) $to->id], 'moving money on it')) !== null) {
                throw ValidationException::withMessages(['to_invoice_id' => $setOff->message]);
            }

            $allocation = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->where('sales_invoice_id', $from->id)->lockForUpdate()->first();
            $on = $allocation === null ? 0 : Money::ofTaka((string) $allocation->amount)->minor;

            if ($money->minor > $on) {
                throw ValidationException::withMessages(['amount' => "{$label} has only ".Money::ofMinor($on)->format()." on invoice {$from->invoice_number}."]);
            }

            if ($money->minor > $to->outstanding()->minor) {
                throw ValidationException::withMessages(['amount' => "Invoice {$to->invoice_number} owes only {$to->outstanding()->format()}."]);
            }

            if ($money->minor === $on) {
                $allocation->delete();
            } else {
                $allocation->forceFill(['amount' => Money::ofMinor($on - $money->minor)->toTaka()])->save();
            }

            $target = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->where('sales_invoice_id', $to->id)->lockForUpdate()->first();

            if ($target === null) {
                CustomerReceiptAllocation::create([
                    'business_id' => $receipt->business_id,
                    'customer_receipt_id' => $receipt->id,
                    'sales_invoice_id' => $to->id,
                    'amount' => $money->toTaka(),
                ]);
            } else {
                $target->forceFill(['amount' => Money::ofMinor(Money::ofTaka((string) $target->amount)->minor + $money->minor)->toTaka()])->save();
            }

            if ($to->fresh()->outstanding()->minor < 0 || $receipt->unallocated()->minor < 0) {
                throw new \LogicException("{$label}: the move would overpay an invoice.");
            }

            CorrectionLog::event('reallocated', $by, $receipt, [
                'from' => $from->invoice_number,
                'to' => $to->invoice_number,
                'amount' => $money->toTaka(),
                'reason' => $reason->display(),
            ]);

            return $receipt->fresh('allocations');
        });
    }
}
