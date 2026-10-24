<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): how much of one supplier payment left
 * one money account. Only a SPLIT payment has these rows; a payment from one
 * account is read from its own `money_account_id` (SupplierPayment::cashLines).
 */
class SupplierPaymentMoneyLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'supplier_payment_id',
        'money_account_id',
        'sort',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'sort' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
