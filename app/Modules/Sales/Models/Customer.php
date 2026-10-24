<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HoldsIdentityDocuments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Contracts\OpensAttachmentCollections;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Foundation\Support\ReferenceScan;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\CustomerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Who we sell to.
 *
 * ⚠ A walk-in customer is a REAL ROW, not a null customer_id. A null means every
 * report has to special-case it, and it makes a counter sale indistinguishable
 * from a data-entry mistake where somebody forgot to pick the customer.
 */
class Customer extends Model implements ControlsAttachmentAccess, OpensAttachmentCollections, Removable
{
    use BelongsToBusiness, HoldsIdentityDocuments, LogsActivity, Recyclable;

    /** ⭐ Plan D11: the Owner opens a customer's NID scan; see HoldsIdentityDocuments. */
    public function identityPermission(): string
    {
        return 'sales.customers.identity.view';
    }

    public function documentManagePermission(): string
    {
        return 'sales.customers.manage';
    }

    /** The code of the per-business walk-in row planted by WalkInCustomerSeeder. */
    public const WALK_IN_CODE = 'WALK-IN';

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'type',
        'contact_name',
        // ⭐ Phase 2 (17 Oct 2026): the employee this staff customer IS. Set by
        // Customer::forEmployee only, unique where present.
        'employee_id',
        // ⭐ D-B (18 Oct 2026): the proprietor (user) this owner customer IS.
        // Set by Customer::forProprietor only, unique where present.
        'owner_user_id',
        'phone',
        'email',
        // ⚠ Optional, never required, and with NO VAT dependency. Storing a tax
        // identifier is recording an attribute of the customer; VAT treatment is
        // how a sale is taxed. An individual consumer is never asked for a BIN.
        'bin',
        'tin',
        'address',
        'payment_terms',
        'credit_days',
        'credit_limit',
        // ⚠ A FK to price_groups, not free text - a typo can no longer create
        // a phantom group that silently prices a distributor at retail. Null
        // means RETAIL, the default group (D-PRC-2); the mapping lives in
        // ResolveSellingPrice only.
        'price_group_id',
        'is_system',
        'is_active',
        'note',
    ];

    /**
     * ⚠⚠ The database defaults, repeated on the model ON PURPOSE. A column
     * default only applies once the row is written, so `Customer::create([...])`
     * without them returns an instance whose `is_active` is NULL - and a caller
     * checking `! $customer->is_active` then refuses a customer the database
     * considers perfectly active.
     *
     * Found on 2026-08-04: the delivery action rejected every freshly created
     * customer with "that customer is no longer active". ⚠ The slice 1 test
     * hid it by asserting on `->fresh()`, which reads the row back from the
     * database and therefore never sees the state a caller actually holds.
     *
     * Same convention as Account and MoneyAccount.
     */
    protected $attributes = [
        'type' => 'retail',
        'is_system' => false,
        'is_active' => true,
    ];

    /**
     * ⚠⚠ The matching key, derived in ONE place so it cannot drift from `phone`.
     * Deliberately NOT fillable: a caller that set one but not the other would
     * produce a customer nobody can find by phone. `phone` itself is never
     * rewritten - the user's input is theirs (C-CRM-12).
     */
    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            $customer->phone_normalized = PhoneIdentity::normalize($customer->phone);
        });
    }

    /**
     * The leads that became this customer.
     *
     * ⚠⚠ THIS IS WHAT KEEPS ATTRIBUTION REPORTABLE AFTER CONVERSION. A lead's
     * activities and attributions are never copied onto the customer - copying
     * would make "when did we first speak" and "which campaign produced this"
     * answerable twice, differently, and the two answers would drift. Instead
     * the link is followed: campaign ROI for a paying customer is read through
     * `$customer->leads->flatMap->attributions`.
     *
     * ⚠ hasMany rather than hasOne on purpose. Two prospects can turn out to be
     * the same company, and both may legitimately be converted into it.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'converted_customer_id');
    }

    public function attributions(): MorphMany
    {
        return $this->morphMany(CrmAttribution::class, 'subject');
    }

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class);
    }

    /**
     * ⭐ Joy, 26 Sep 2026 (Q6): payment terms are a number of days, 10 unless
     * the customer has their own, and every invoice gets a due date from it.
     */
    public const DEFAULT_CREDIT_DAYS = 10;

    /**
     * How many days after its date an invoice for this customer falls due.
     * The walk-in row is never sold on account, so its invoices are due the
     * day they are raised.
     */
    /** Sent with every row, so a list can say "10 days (default)" without asking. */
    protected $appends = ['credit_days_effective'];

    public function getCreditDaysEffectiveAttribute(): int
    {
        return $this->creditDays();
    }

    public function creditDays(): int
    {
        if ($this->type === CustomerType::WalkIn || $this->type === CustomerType::Staff) {
            return 0;
        }

        return $this->credit_days ?? self::DEFAULT_CREDIT_DAYS;
    }

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'credit_limit' => 'decimal:4',
            'credit_days' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'phone', 'payment_terms', 'credit_days', 'credit_limit', 'price_group_id', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The walk-in counter customer for the business in context.
     *
     * ⚠ Returns null rather than creating one. A seeder plants it; a getter that
     * creates master data on read is how a test passes against a row production
     * does not have.
     */
    public static function walkIn(): ?self
    {
        return static::query()->where('code', self::WALK_IN_CODE)->first();
    }

    /** The same test walkIn() finds the row by, so the two cannot disagree. */
    public function isWalkIn(): bool
    {
        return $this->code === self::WALK_IN_CODE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Staff customers (Phase 2, ChatGPT ruling Q2, 17 Oct 2026) ───────────

    /** The permissions that open a staff customer's details, invoices and balance. */
    public const STAFF_VISIBILITY_PERMISSIONS = ['hrm.employees.manage', 'hrm.staff_purchase.cost_basis.view', 'sales.reports.costs'];

    public function isStaff(): bool
    {
        return $this->type === CustomerType::Staff;
    }

    /**
     * Whether this person may see staff customers at all: the Owner, the HR
     * authority (`hrm.employees.manage`) and Finance (seeded the cost-basis
     * and cost-reports permissions). Everybody else never sees an employee's
     * purchases in a customer list or picker.
     */
    public static function staffVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach (self::STAFF_VISIBILITY_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⭐ The general lists and pickers (customers screen, counter, orders,
     * invoices, deliveries, quotations, receipts, prices, CRM): staff rows are
     * left out unless the person holds a permission that opens them.
     * Relations and lookups by key are untouched, so an invoice still knows
     * its customer.
     */
    public function scopeListable(Builder $query, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        if (self::staffVisibleTo($user)) {
            return $query;
        }

        return $query->where($query->getModel()->getTable().'.type', '<>', CustomerType::Staff->value);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * ⭐ The supplier this customer also is (decision D-F, 18 Oct 2026): one
     * firm on both sides of the books. Written only by LinkSupplierCustomer;
     * the two ledgers stay apart (AR here, AP there).
     */
    public function alsoSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** The one customer row of this employee, if a request ever created it. */
    public static function ofEmployee(int $employeeId): ?self
    {
        return static::query()->where('employee_id', $employeeId)->first();
    }

    /**
     * The employee's customer row, created on the first staff purchase
     * request (ruling Q2: "preferably at first request"). Type `staff`, the
     * STAFF price group when the business has one (compatibility only: the
     * price is computed from cost), due on the day like the walk-in row.
     */
    public static function forEmployee(Employee $employee): self
    {
        $existing = static::ofEmployee($employee->id);

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'business_id' => $employee->business_id,
            'code' => 'STAFF-'.$employee->employee_code,
            'name' => $employee->fullName(),
            'type' => CustomerType::Staff,
            'employee_id' => $employee->id,
            'phone' => $employee->phone,
            'price_group_id' => PriceGroup::query()->where('code', 'STAFF')->value('id'),
            'credit_days' => 0,
            'is_system' => false,
            'is_active' => true,
            'note' => "Staff purchase account of {$employee->employee_code}. Created by the system; invoices are settled at the counter or through payroll.",
        ]);
    }

    // ── The owner customer (D-B, Joy, 18 Oct 2026: "Cost price") ─────────────

    /** The price group an owner customer is priced in: basis COST. */
    public const OWNER_GROUP_CODE = 'OWNER';

    public function isOwner(): bool
    {
        return $this->type === CustomerType::Owner;
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** The owner customer of this proprietor in the business in context, if it was ever made. */
    public static function ofOwner(int $userId): ?self
    {
        return static::query()->where('owner_user_id', $userId)->first();
    }

    /**
     * ⭐ The proprietor's own customer account, made on demand ("Create Moin's
     * customer account" under Settings > Company). Type `owner`, the OWNER
     * group (basis COST: the FIFO batch cost, no MRP fallback, no cap), the
     * normal credit days, so he pays now at the counter or later by an
     * ordinary customer receipt. No equity entry, ever: this is a sale.
     *
     * ⚠ Linked to his employee record through `employee_id` only when no
     * other customer row holds it already (a staff purchase account of his
     * stays what it is: never adopted, never renamed).
     */
    public static function forProprietor(User $proprietor, int $businessId): self
    {
        $existing = static::query()->where('business_id', $businessId)->where('owner_user_id', $proprietor->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $employee = Employee::query()->where('user_id', $proprietor->id)->first();
        $employeeFree = $employee !== null && ! static::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->exists();

        return static::create([
            'business_id' => $businessId,
            'code' => 'OWNER-'.$proprietor->id,
            'name' => $employee?->fullName() ?? $proprietor->name,
            'type' => CustomerType::Owner,
            'owner_user_id' => $proprietor->id,
            'employee_id' => $employeeFree ? $employee->id : null,
            'phone' => $employee?->phone,
            'email' => $proprietor->email,
            'price_group_id' => PriceGroup::query()->where('business_id', $businessId)->where('code', self::OWNER_GROUP_CODE)->value('id'),
            'is_system' => false,
            'is_active' => true,
            'note' => 'The proprietor buying for himself, at the FIFO batch cost (D-B, 18 Oct 2026). Created from Settings > Company; pays now at the counter or later by a customer receipt.',
        ]);
    }

    // --- Recycle bin and Reactivate (B13, 24 Oct 2026; FINAL-PLAN 3.2) -------

    /**
     * Delete goes to the Recycle bin only when nothing ever pointed at this
     * customer: no order, challan, invoice, receipt, price, note, phone book
     * link or ledger line (ReferenceScan reads the schema, so a table added
     * later counts too). Otherwise one sentence, and Deactivate instead.
     */
    public function whyNotRemovable(): ?string
    {
        if ($this->is_system || $this->employee_id !== null || $this->owner_user_id !== null) {
            return "{$this->name} is made by the system and cannot be deleted.";
        }

        if ($this->merged_into_id !== null) {
            return "{$this->name} was merged into another customer and stays for its history.";
        }

        $uses = ReferenceScan::count($this, ['customer_id']);

        return $uses === [] ? null : "{$this->name} is used (".ReferenceScan::describe($uses).'). Deactivate it instead.';
    }

    public function whyNotDeactivatable(): ?string
    {
        return $this->is_system ? 'The walk-in customer is part of the system and cannot be deactivated.' : null;
    }

    /** Switched off ones come back; a merged one points at where it went. */
    public function whyNotReactivatable(): ?string
    {
        return match (true) {
            $this->is_active => "{$this->name} is already active.",
            $this->merged_into_id !== null => "{$this->name} was merged into another customer. Use that one.",
            default => null,
        };
    }

    public function binLabel(): string
    {
        return 'Customer '.$this->name.($this->phone ? ", {$this->phone}" : '');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
