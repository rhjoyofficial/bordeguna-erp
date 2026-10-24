<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * ⭐ A price credit note on an issued invoice, through the corrections engine
 * (24 Oct 2026; see SalesInvoiceAdditionPolicy).
 */
class PriceCreditPolicy extends SalesInvoiceAdditionPolicy
{
    public const TYPE = 'sales.price_credit';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Price credit';
    }

    protected function ownBlockers(SalesInvoice $invoice): array
    {
        if ($invoice->outstanding()->minor <= 0) {
            return [new Blocker('nothing_owed', "{$invoice->invoice_number} owes nothing, so a lower price is given back by reversing or re-allocating the payment instead.")];
        }

        $setOff = SalesDependencies::setOffBlocker([(int) $invoice->id], 'a credit note');

        return $setOff !== null && $setOff->code === 'set_off_waiting' ? [$setOff] : [];
    }
}
