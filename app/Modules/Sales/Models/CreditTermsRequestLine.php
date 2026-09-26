<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's proposed credit limit and days, beside what they have now.
 */
class CreditTermsRequestLine extends Model
{
    protected $fillable = [
        'credit_terms_request_id',
        'customer_id',
        'current_credit_limit',
        'current_credit_days',
        'proposed_credit_limit',
        'proposed_credit_days',
        'applied_over_credit_limit',
        'applied_over_credit_days',
    ];

    protected function casts(): array
    {
        return [
            'current_credit_limit' => 'decimal:4',
            'proposed_credit_limit' => 'decimal:4',
            'applied_over_credit_limit' => 'decimal:4',
            'current_credit_days' => 'integer',
            'proposed_credit_days' => 'integer',
            'applied_over_credit_days' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CreditTermsRequest::class, 'credit_terms_request_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** True when the proposed figures differ from what the customer had at submission. */
    public function changesLimit(): bool
    {
        return $this->numbersDiffer($this->current_credit_limit, $this->proposed_credit_limit);
    }

    public function changesDays(): bool
    {
        return (int) ($this->current_credit_days ?? -1) !== (int) ($this->proposed_credit_days ?? -1);
    }

    private function numbersDiffer(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return abs((float) $a - (float) $b) >= 0.00005;
    }
}
