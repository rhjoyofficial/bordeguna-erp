<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HoldsIdentityDocuments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Contracts\OpensAttachmentCollections;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model implements ControlsAttachmentAccess, OpensAttachmentCollections
{
    use BelongsToBusiness, HasFactory, HoldsIdentityDocuments, LogsActivity;

    /** ⭐ Plan D11: the Owner opens a supplier's NID scan; see HoldsIdentityDocuments. */
    public function identityPermission(): string
    {
        return 'purchasing.suppliers.identity.view';
    }

    public function documentManagePermission(): string
    {
        return 'purchasing.suppliers.manage';
    }

    // Finance decision C4: `supplier_type` picks the TDS/VDS rates that apply.
    // ⚠ It holds the KEY of one of the business's own SupplierType rows (17 Oct
    // 2026); the fixed TYPES list that lived here is gone. See SupplierTypes.

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'supplier_type',
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
            'bank_details_changed_at' => 'datetime',
        ];
    }

    /** Where the supplier is paid to: a change here is approved on the next payment. */
    public const BANK_FIELDS = ['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number', 'mfs_number'];

    /**
     * ⭐ Purchasing approvals (ruling P7, 18 Oct 2026): the first payment after
     * the bank or wallet details of an EXISTING supplier change goes to a
     * second person. Stamped on every save path (screen, import, quick create),
     * never on the first save.
     */
    protected static function booted(): void
    {
        static::updating(function (self $supplier): void {
            foreach (self::BANK_FIELDS as $field) {
                if ($supplier->isDirty($field) && trim((string) $supplier->getOriginal($field)) !== trim((string) $supplier->{$field})) {
                    $supplier->bank_details_changed_at = now();

                    return;
                }
            }
        });
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

    /**
     * ⭐ The customer this supplier also is (decision D-F, 18 Oct 2026). The
     * link lives on the customer row (customers.supplier_id, unique).
     */
    public function alsoCustomer(): HasOne
    {
        return $this->hasOne(Customer::class, 'supplier_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** ⭐ Joy's answer 18: certifications, only when relevant. */
    public function certifications(): HasMany
    {
        return $this->hasMany(SupplierCertification::class);
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
