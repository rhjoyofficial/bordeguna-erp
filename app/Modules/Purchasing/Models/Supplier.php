<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'payment_terms',
        'bin',
        'tin',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_number',
        'mfs_number',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /**
     * What this supplier is owed, right now.
     *
     * ⚠⚠ DERIVED, never stored, and summed in MINOR UNITS through Money rather
     * than by adding decimal strings. `SUM(total) - SUM(amount)` in one query
     * would be faster and would reintroduce exactly the float arithmetic this
     * project already removed from stock quantities: `outstanding()` per bill is
     * integer paisa, and adding those cannot drift.
     *
     * ⚠ Reads `$this->bills` rather than querying, so an eager-loaded caller
     * pays one query for every supplier instead of one per supplier. The payment
     * form lists every supplier, so the difference is the whole page.
     */
    public function outstanding(): Money
    {
        return $this->bills->reduce(
            fn (Money $carry, SupplierBill $bill) => $carry->plus($bill->outstanding()),
            Money::zero(),
        );
    }
}
