<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\SetOffLine;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ What else in the books leans on a sales invoice (corrections wave 1, 20 Oct
 * 2026), asked by the invoice and the receipt policies alike.
 *
 * ⚠⚠ A SET-OFF IS THE BINDING ONE (Joy's brief): it was agreed with the party
 * against what the invoice still owed at the time, after the receipts already
 * on it. Crediting that invoice, or taking a receipt off it, would leave the
 * set-off clearing a balance that is no longer there, and the party statement
 * (customer and supplier linked) would stop agreeing with 1140 and 2110. So it
 * is refused, with the set-off named, until the set-off is reversed first
 * (Set-offs > Reverse, SetOffCorrector).
 */
final class SalesDependencies
{
    /**
     * The set-off (posted, or still waiting for approval) that names any of
     * these invoices, as a blocker; null when none does.
     *
     * @param  list<int>  $invoiceIds
     */
    public static function setOffBlocker(array $invoiceIds, string $what): ?Blocker
    {
        if ($invoiceIds === []) {
            return null;
        }

        // Posted: the set-off's party adjustment is allocated to the invoice.
        $posted = PartyAdjustmentAllocation::query()
            ->whereIn('sales_invoice_id', $invoiceIds)
            ->whereIn('party_adjustment_id', PartyAdjustment::query()->where('kind', 'set_off')->select('id'))
            ->first(['party_adjustment_id', 'sales_invoice_id']);

        if ($posted !== null) {
            $setOff = SetOff::query()->where('party_adjustment_id', $posted->party_adjustment_id)->value('number');
            $invoice = SalesInvoice::query()->whereKey($posted->sales_invoice_id)->value('invoice_number');

            return new Blocker(
                'set_off_depends',
                "Invoice {$invoice} is cleared by set-off {$setOff}, so {$what} would leave that set-off clearing money that is no longer owed; reverse the set-off first.",
                url: self::setOffsUrl(),
                resolvableBy: 'accountant',
            );
        }

        $pending = SetOffLine::query()
            ->whereIn('sales_invoice_id', $invoiceIds)
            ->whereIn('set_off_id', SetOff::query()->where('status', SetOff::PENDING)->select('id'))
            ->first(['set_off_id', 'sales_invoice_id']);

        if ($pending !== null) {
            $setOff = SetOff::query()->whereKey($pending->set_off_id)->value('number');
            $invoice = SalesInvoice::query()->whereKey($pending->sales_invoice_id)->value('invoice_number');

            return new Blocker(
                'set_off_waiting',
                "Invoice {$invoice} is named on set-off {$setOff}, which is waiting for approval; decide that set-off first.",
                url: self::setOffsUrl(),
                resolvableBy: 'accountant',
            );
        }

        return null;
    }

    public static function voucherNumber(?int $voucherId): ?string
    {
        return $voucherId === null ? null : DB::table('vouchers')->where('id', $voucherId)->value('voucher_number');
    }

    private static function setOffsUrl(): ?string
    {
        return app('router')->has('accounting.set-offs.index') ? route('accounting.set-offs.index') : null;
    }
}
