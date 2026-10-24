<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Corrections\SalesDependencies;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturnAllocation;
use App\Modules\Sales\Models\SalesReturnInvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ What else leans on a sales invoice before it is credited in full
 * (corrections wave 1, 20 Oct 2026). Each refusal names the document and
 * what to do first:
 *
 *   - a set-off clears it (posted, or waiting for approval): reverse or decide
 *     the set-off first (Joy's brief, SalesDependencies);
 *   - a write-off, discount, rounding or price credit note is on it;
 *   - goods it billed came back on a return;
 *   - an extra charge (debit note) was raised on it;
 *   - a receipt typed against it (not an order's advance, which simply goes
 *     back to the order, and not a counter sale's own cash, which Void sale
 *     takes back with it): on Credit note, re-allocate or reverse it first;
 *     on Correct it moves to the new invoice, so it is no blocker;
 *   - a counter sale's cash matched on the bank statement.
 */
class InvoiceDependencyCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof SalesInvoice || $document->isReversed() || ! in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true)) {
            return null;
        }

        $number = $document->invoice_number;
        $what = $action === DocumentAction::Correct ? 'correcting it' : 'crediting it';

        if (($setOff = SalesDependencies::setOffBlocker([(int) $document->id], $what)) !== null) {
            return $setOff;
        }

        $adjustment = PartyAdjustmentAllocation::query()->where('sales_invoice_id', $document->id)->first(['party_adjustment_id', 'amount']);

        if ($adjustment !== null) {
            $kind = PartyAdjustment::query()->whereKey($adjustment->party_adjustment_id)->first(['id', 'kind']);
            $priceCredit = SalesCreditNote::query()->where('party_adjustment_id', $adjustment->party_adjustment_id)->value('credit_note_number');
            $amount = Tk::of(Money::ofTaka((string) $adjustment->amount)->minor);
            // ⭐ B11 (24 Oct 2026): a customer advance used on it is reversed on its own.
            $advanceUse = CustomerAdvanceApplication::query()->where('party_adjustment_id', $adjustment->party_adjustment_id)->value('number');

            if ($advanceUse !== null) {
                return new Blocker(
                    'advance_used',
                    "Invoice {$number} is paid {$amount} from the customer's advance ({$advanceUse}); reverse that use under Sales, Advances first.",
                    resolvableBy: 'accountant',
                );
            }

            return new Blocker(
                'adjusted',
                $priceCredit !== null
                    ? "Invoice {$number} already has price credit {$priceCredit} of {$amount}, so it cannot be credited again in full."
                    : "Invoice {$number} carries a ".strtolower($kind?->label() ?? 'adjustment')." of {$amount}; remove it under Accounting first.",
                resolvableBy: 'accountant',
            );
        }

        $lineIds = SalesInvoiceLine::query()->where('sales_invoice_id', $document->id)->pluck('id');

        if (SalesReturnAllocation::query()->where('sales_invoice_id', $document->id)->exists()
            || SalesReturnInvoiceLine::query()->whereIn('sales_invoice_line_id', $lineIds)->exists()) {
            return new Blocker(
                'returned',
                "Goods billed on invoice {$number} came back on a sales return, so it cannot be credited in full; give a price credit instead.",
            );
        }

        $extra = SalesInvoice::query()->live()->where('debit_note_for_invoice_id', $document->id)->value('invoice_number');

        if ($extra !== null) {
            return new Blocker('extra_charge', "Invoice {$number} has extra charge {$extra} raised on it; credit that one first.");
        }

        $bundle = SalesInvoiceActionPolicy::voidBundle($document);
        $own = $bundle?->receipts->modelKeys() ?? [];

        if ($own !== [] && DB::table('bank_statement_lines')->whereIn('matched_journal_line_id', DB::table('journal_lines')
            ->whereIn('journal_id', DB::table('journals')->where('source_type', (new CustomerReceipt)->getMorphClass())->whereIn('source_id', $own)->select('id'))
            ->select('id'))->exists()) {
            return new Blocker('bank_matched', "The money for invoice {$number} is matched on the bank statement; unmatch it first.", resolvableBy: 'accountant');
        }

        if ($action === DocumentAction::Reverse) {
            $paid = CustomerReceiptAllocation::query()
                ->where('sales_invoice_id', $document->id)
                ->whereNotIn('customer_receipt_id', $own)
                ->whereIn('customer_receipt_id', CustomerReceipt::query()->whereNull('sales_order_id')->select('id'))
                ->sum('amount');

            if (Money::ofTaka((string) ($paid ?: '0'))->minor > 0) {
                return new Blocker(
                    'paid',
                    'Payments of '.Tk::of(Money::ofTaka((string) $paid)->minor)." are on invoice {$number}; re-allocate or reverse them first, or use Correct, which moves them to the new invoice.",
                    resolvableBy: 'accountant',
                );
            }
        }

        return null;
    }
}
