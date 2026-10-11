<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's use of a courier account: from which pickup store, and into
 * which courier money account (1145) the cash on delivery is owed.
 *
 * ⚠ This row, not the account, holds the store and the money mapping (ChatGPT
 * ruling 11), because one account serves two businesses that ship from
 * different stores and keep separate books.
 *
 * ⚠ Business-scoped by the global scope. A raw query on this table must still
 * filter `business_id` by hand.
 */
class CourierAccountBusiness extends Model
{
    use BelongsToBusiness;

    protected $table = 'courier_account_business';

    protected $fillable = [
        'courier_account_id', 'business_id', 'provider_store_id', 'provider_store_name',
        'money_account_id', 'is_default', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
