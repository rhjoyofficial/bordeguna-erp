<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * ⭐ An extra charge (debit note) on an issued invoice, through the corrections
 * engine (24 Oct 2026; see SalesInvoiceAdditionPolicy).
 */
class ExtraChargePolicy extends SalesInvoiceAdditionPolicy
{
    public const TYPE = 'sales.extra_charge';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Extra charge';
    }

    protected function ownBlockers(SalesInvoice $invoice): array
    {
        return $invoice->debit_note_for_invoice_id === null
            ? []
            : [new Blocker('is_extra_charge', "{$invoice->invoice_number} is itself an extra charge, so raise it on the first invoice.")];
    }
}
