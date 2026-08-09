<?php

namespace App\Modules\Sales\Models;

use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Enums\CustomerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Who we sell to.
 *
 * ⚠ A walk-in customer is a REAL ROW, not a null customer_id. A null means every
 * report has to special-case it, and it makes a counter sale indistinguishable
 * from a data-entry mistake where somebody forgot to pick the customer.
 */
class Customer extends Model
{
    use BelongsToBusiness, LogsActivity;

    /** The code of the per-business walk-in row planted by WalkInCustomerSeeder. */
    public const WALK_IN_CODE = 'WALK-IN';

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'type',
        'contact_name',
        'phone',
        'email',
        // ⚠ Optional, never required, and with NO VAT dependency. Storing a tax
        // identifier is recording an attribute of the customer; VAT treatment is
        // how a sale is taxed. An individual consumer is never asked for a BIN.
        'bin',
        'tin',
        'address',
        'payment_terms',
        'credit_limit',
        'price_group',
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

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'credit_limit' => 'decimal:4',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'phone', 'payment_terms', 'credit_limit', 'price_group', 'is_active'])
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
