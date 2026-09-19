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

    protected $fillable = ['voucher_id', 'business_id', 'line_no', 'account_id', 'side', 'amount_minor', 'narration', 'party_type', 'party_id'];

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

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function isDebit(): bool
    {
        return $this->side === 'debit';
    }
}
