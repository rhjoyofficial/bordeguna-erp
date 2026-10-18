<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * A purchasing document about to post, described for PurchasingApprovalGate
 * (ruling P7, 18 Oct 2026): what it is, how much, and the facts each exception
 * reads.
 *
 * ⚠ `amount` is the approval basis, not the cash moved today:
 *   bill     the gross obligation (goods + VAT) less the trade discount
 *   payment  the full value settled (bills + any excess becoming an advance)
 *   advance  the gross advance (before TDS)
 *   refund   what the supplier pays back
 *
 * `payload` is what the domain action needs to run again on approval; another
 * branch's caller (a one-screen "Buy and receive") builds a draft the same way
 * and asks {@see PurchasingApprovalGate::assess()} before posting anything.
 */
final class PurchaseDraft
{
    /**
     * @param  'bill'|'payment'|'advance'|'refund'  $kind
     * @param  array<string, mixed>  $payload
     * @param  list<array{label: string, billed: Money, ordered: Money}>  $priceLines  billed value vs the order price for the same quantity
     */
    public function __construct(
        public readonly string $kind,
        public readonly Supplier $supplier,
        public readonly Money $amount,
        public readonly Carbon $documentDate,
        public readonly ?string $reference,
        public readonly string $summary,
        public array $payload = [],
        public readonly int $nonPoLines = 0,
        public readonly array $priceLines = [],
        public readonly ?Money $discount = null,
        public readonly ?Money $discountBase = null,
        public readonly ?string $overApplication = null,
        public readonly ?MoneyAccount $moneyAccount = null,
        // The row the action has already written inside its own (about to roll
        // back) transaction, so it does not count as the supplier's history.
        public readonly ?int $inProgressId = null,
    ) {}

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'bill' => 'supplier bill',
            'payment' => 'supplier payment',
            'advance' => 'supplier advance',
            'refund' => 'advance refund',
            default => $this->kind,
        };
    }

    /** Something the caller does once the document exists (bill: apply advances). */
    public function withFollowUp(string $key, mixed $value): self
    {
        $this->payload['follow_ups'][$key] = $value;

        return $this;
    }
}
