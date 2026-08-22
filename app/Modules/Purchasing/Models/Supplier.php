<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

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

    /**
     * ⚠ Same convention as Warehouse, Account, MoneyAccount and Customer, and
     * it was MISSING here: a freshly created Supplier had a NULL `is_active`
     * in memory until reloaded, so any caller checking `! $supplier->is_active`
     * refused a supplier the database considers active - and the activity log
     * recorded a phantom is_active null→true diff on every update.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * ⚠⚠ Same gap as Warehouse: the supplier CODE is what imports and the AP
     * subledger identify a party by, and a PREMI→PREME style correction used
     * to leave activity_log untouched while a Product rename was logged.
     * Identity and money-relevant fields only - not the whole contact card.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'payment_terms', 'bank_account_number', 'mfs_number', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
