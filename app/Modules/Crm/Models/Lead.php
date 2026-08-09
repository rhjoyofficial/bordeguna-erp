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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    /**
     * ⚠⚠ `duplicate_of_lead_id`, `merged_at` and `merged_by` are ALSO absent,
     * for the same reason: `LinkDuplicateLead` is their only writer, and a lead
     * that could be marked a duplicate by a stray mass assignment would vanish
     * from the working list with nobody having decided it should.
     *
     * ⚠⚠ `converted_customer_id` and `converted_at` are DELIBERATELY ABSENT.
     * Conversion asserts that a customer exists and that this prospect became
     * them; it is the one lead change with a consequence outside CRM. Leaving
     * these fillable meant any mass assignment - a settings form, an importer, a
     * future API - could claim a conversion with nothing behind it, and every
     * conversion figure the business ever reads would be unreliable.
     *
     * `ConvertLead` sets them with forceFill, which works regardless of
     * $fillable and is the ONLY path that does. Same reasoning as
     * businesses.is_default and businesses.vat_enabled.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id', 'name', 'phone', 'email', 'address',
        'brand_id', 'status', 'assigned_to_user_id', 'qualification_note',
        'lost_reason_id', 'lost_at', 'note',
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
            'merged_at' => 'datetime',
        ];
    }

    /**
     * The lead this one turned out to be a duplicate of.
     *
     * ⚠⚠ Nothing was moved when this was set. This lead keeps its own
     * activities, tasks, interests and attributions; the link only says which
     * one should be WORKED. Re-parenting historical rows would make "which
     * campaign produced this" answer differently than it did yesterday.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_lead_id');
    }

    /** The leads that turned out to be this one. */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_lead_id');
    }

    public function isDuplicate(): bool
    {
        // ⚠ Derived, not a status. An eighth LeadStatus case would shatter
        // every exhaustive match over the enum to say what this column says.
        return $this->duplicate_of_lead_id !== null;
    }

    /**
     * ⚠ The working list: leads somebody should actually act on. A prospect
     * nobody should call twice must not sit in the queue twice.
     */
    public function scopeWorkable(Builder $query): Builder
    {
        return $query->whereNull('duplicate_of_lead_id');
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
