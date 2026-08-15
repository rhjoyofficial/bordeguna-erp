<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Carbon;

/**
 * One movement on a customer's statement: a document, its signed effect on what
 * the customer owes, and the balance after it.
 *
 * ⚠ Not `StatementLine` - that name is taken by the financial statements'
 * per-account line, and the two are different shapes for different readers.
 *
 * ⚠ `amount` is signed the way the customer reads it: positive means they owe
 * more (invoice, refund), negative means they owe less (receipt, return).
 */
final class CustomerStatementLine
{
    public function __construct(
        public readonly Carbon $date,
        /** 'invoice' | 'return' | 'receipt' | 'refund' */
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $reference,
        public readonly Money $amount,
        public readonly Money $balance,
    ) {}
}
