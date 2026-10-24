<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money from one of the group's businesses to another (finance decision C2).
 *
 * ⚠ Belongs to TWO businesses, so it carries no tenant scope: read it through
 * {@see self::scopeInvolving()}.
 */
class InterBusinessTransfer extends Model
{
    // ⭐ Corrections wave 2 (24 Oct 2026): Reverse in both businesses at once; kept and marked.
    use Reversible;

    protected $fillable = ['from_business_id', 'to_business_id', 'from_money_account_id', 'to_money_account_id', 'transferred_on', 'amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['transferred_on' => 'date:Y-m-d', 'amount' => 'decimal:4'];
    }

    public function scopeInvolving(Builder $query, int $businessId): Builder
    {
        return $query->where(fn ($q) => $q->where('from_business_id', $businessId)->orWhere('to_business_id', $businessId));
    }

    public function fromBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'from_business_id');
    }

    public function toBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'to_business_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_money_account_id')->withoutGlobalScopes();
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id')->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
