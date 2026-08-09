<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A prospect: somebody who might buy, and where they came from.
 *
 * ⚠⚠ A LEAD IS NOT A CUSTOMER, AND NEVER BECOMES ONE BY ACCIDENT (C-CRM-15).
 * Nothing in this slice creates a `customers` row. Conversion is a deliberate
 * Phase 3 action that sets `converted_customer_id` and the status together.
 *
 * ⚠ Every personal field is nullable on purpose. Joy's D7: no retention period
 * is invented and nothing is auto-purged, but a future privacy rule must be
 * implementable by ANONYMISATION - blanking the person while the row, its
 * status, its campaign and its attributions survive, so historical campaign
 * counts stay true (C-CRM-16).
 */
class Lead extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    /**
     * ⚠ `phone_normalized` is deliberately NOT fillable. It is derived in
     * `booted()` from `phone` and must never be supplied by a caller, or the two
     * could disagree and a lead would become unfindable by phone.
     */
    protected $fillable = [
        'business_id', 'name', 'phone', 'email', 'address',
        'brand_id', 'status', 'assigned_to_user_id', 'qualification_note',
        'lost_reason_id', 'lost_at', 'converted_customer_id', 'converted_at', 'note',
    ];

    /**
     * ⚠ The database default repeated on the model. A column default applies
     * only once the row is written, so a freshly created instance would carry a
     * NULL status and a caller reading `$lead->status->value` would fatal on
     * null. Same convention as Customer, Account and MoneyAccount.
     */
    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * ⚠⚠ THE MATCHING KEY IS DERIVED HERE, IN ONE PLACE, so it cannot drift from
     * `phone`. Saving is the only moment both are known, and a controller that
     * remembered to set one but not the other would produce a lead nobody can
     * find by phone. `phone` itself is never touched (C-CRM-12).
     */
    protected static function booted(): void
    {
        static::saving(function (self $lead): void {
            $lead->phone_normalized = PhoneIdentity::normalize($lead->phone);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone', 'email', 'status', 'brand_id', 'assigned_to_user_id', 'lost_reason_id', 'converted_customer_id'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** ⚠ C-CRM-11 / D4: nullable. LaLaDia's, Bionic-Garden's, or neither. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * ⚠⚠ C-CRM-2 / D3: a USER, not an Employee. `employees` has no `business_id`
     * and is shared across businesses. Membership of a business is the
     * `business_user` PIVOT, which is why this cannot be validated with a
     * column-scoped exists rule - see LeadController::validated().
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** ⚠ C-CRM-10 / D6: a Reason with context = 'lead_lost', checked in validation. */
    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(Reason::class, 'lost_reason_id');
    }

    /**
     * ⚠⚠ C-CRM-5: THE ONE AND ONLY CONVERSION LINK. There is deliberately no
     * `customers.converted_from_lead_id` - two links pointing at each other is a
     * consistency problem with no owner.
     */
    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function attributions(): MorphMany
    {
        return $this->morphMany(CrmAttribution::class, 'subject');
    }

    /**
     * The acquisition history, oldest first.
     *
     * ⚠ First and latest are DERIVED (C-CRM-6). Nothing caches them, so nothing
     * can disagree with the append-only history that produced them.
     */
    public function firstAttribution(): ?CrmAttribution
    {
        return $this->attributions()->orderBy('occurred_at')->orderBy('id')->first();
    }

    public function latestAttribution(): ?CrmAttribution
    {
        return $this->attributions()->orderByDesc('occurred_at')->orderByDesc('id')->first();
    }

    public function scopeUnworked(Builder $query): Builder
    {
        return $query->where('status', LeadStatus::New);
    }
}
