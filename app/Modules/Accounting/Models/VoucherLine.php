<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VoucherLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['voucher_id', 'business_id', 'line_no', 'account_id', 'side', 'amount_minor', 'narration', 'party_type', 'party_id', 'loan_contract_id', 'bill_wise', 'tds_rate_id', 'vds_rate_id'];

    protected function casts(): array
    {
        // ⭐ Bill-wise details (20 Oct 2026): [{ref, amount}], null = oldest first.
        return ['bill_wise' => 'array'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    /** The register loan this line belongs to (loans plan v2, phase 2), when it is on a loan head. */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanContract::class, 'loan_contract_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function isDebit(): bool
    {
        return $this->side === 'debit';
    }
}
