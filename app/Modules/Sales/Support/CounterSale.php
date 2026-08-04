<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * What one counter sale produced.
 *
 * ⚠ All three documents, because all three genuinely exist. A counter sale is
 * not a special kind of sale with fewer records - it is the ordinary sale whose
 * three steps happened in one transaction.
 */
final readonly class CounterSale
{
    public function __construct(
        public Delivery $delivery,
        public SalesInvoice $invoice,
        public ?CustomerReceipt $receipt,
    ) {}
}
