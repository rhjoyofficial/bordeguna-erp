<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\LeadImportance;
use App\Modules\Crm\Enums\LeadPotential;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Scopes\LeadVisibilityScope;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
class Lead extends Model implements ControlsAttachmentAccess
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
    /**
     * ⚠⚠ `verified_at`, `verified_by_user_id`, `archived_at` and
     * `archived_by_user_id` are DELIBERATELY ABSENT, for the same reason
     * conversion is: verification asserts that a named person checked this lead
     * is real, and archiving takes it out of everybody's working list. Both are
     * decisions somebody makes, and a mass assignment - a settings form, an
     * importer, a future API - must not be able to claim either. `VerifyLead`
     * and `ArchiveLead` are their only writers and use forceFill.
     *
     * ⚠ `shop_phone_key` is not fillable either, for the reason
     * `phone_normalized` is not: it is derived in `booted()` and a caller
     * supplying it could make the shop's identity disagree with its phone.
     */
    protected $fillable = [
        'business_id', 'lead_type', 'name', 'contact_name', 'phone', 'secondary_phone', 'email', 'address',
        'district', 'thana', 'area', 'market', 'landmark', 'potential', 'importance',
        'brand_id', 'status', 'assigned_to_user_id', 'qualification_note',
        'lost_reason_id', 'lost_at', 'note',
    ];

    /**
     * ⚠ The database default repeated on the model. A column default applies
     * only once the row is written, so a freshly created instance would carry a
     * NULL status and a caller reading `$lead->status->value` would fatal on
     * null. Same convention as Customer, Account and MoneyAccount.
     */
    protected $attributes = ['status' => 'new', 'lead_type' => 'customer'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'lead_type' => LeadType::class,
            'potential' => LeadPotential::class,
            'importance' => LeadImportance::class,
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
            'merged_at' => 'datetime',
            'verified_at' => 'datetime',
            'archived_at' => 'datetime',
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
        // ⚠ Archived leads leave the working list for the same reason duplicates
        // do: somebody decided nobody should act on this. They are still
        // readable, still carry their history, and still count in the management
        // numbers - they are simply not work any more.
        return $query->whereNull('duplicate_of_lead_id')->whereNull('archived_at');
    }

    /**
     * Leads reachable on a normalised number, on EITHER of their phones.
     *
     * ⚠ Both directions in one place: a new lead's main number matching an old
     * lead's second number is the same shop as the reverse, and a duplicate
     * check that looked only at `phone_normalized` would miss half of them.
     */
    public function scopeReachableOn(Builder $query, string $normalized): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where($this->qualifyColumn('phone_normalized'), $normalized)
            ->orWhere($this->qualifyColumn('secondary_phone_normalized'), $normalized));
    }

    /**
     * ⚠⚠ THE MATCHING KEY IS DERIVED HERE, IN ONE PLACE, so it cannot drift from
     * `phone`. Saving is the only moment both are known, and a controller that
     * remembered to set one but not the other would produce a lead nobody can
     * find by phone. `phone` itself is never touched (C-CRM-12).
     */
    protected static function booted(): void
    {
        // ⭐⭐ Whose leads a person may see: the Owner all of them, everybody
        // else their own and their team's. See LeadVisibilityScope for why this
        // is a scope on the model rather than a filter in each screen.
        static::addGlobalScope(new LeadVisibilityScope);

        static::saving(function (self $lead): void {
            $lead->phone_normalized = PhoneIdentity::normalize($lead->phone);
            // ⚠ Derived beside the first, for the same reason. Never unique:
            // see the migration that added it.
            $lead->secondary_phone_normalized = PhoneIdentity::normalize($lead->secondary_phone);

            // ⭐⭐ The shop's identity, derived in the SAME place and the same
            // moment as the matching key, because it is the same fact seen
            // twice. A shop carries its number here and is therefore unique in
            // the database; a person carries NULL and a unique index ignores
            // NULLs, so households sharing a number are untouched.
            //
            // ⚠ Recomputed on EVERY save rather than only when the phone
            // changes, because changing the TYPE changes this too: a consumer
            // lead corrected to a retailer must start colliding with other
            // retailers from that moment, and a retailer corrected to a
            // consumer must stop.
            $lead->shop_phone_key = $lead->lead_type instanceof LeadType && $lead->lead_type->isShop()
                ? $lead->phone_normalized
                : null;
        });
    }

    /**
     * ⚠⚠ The files on a lead follow the lead. Without this the attachment route
     * would stream a photo of a shop to anybody in the business who guessed its
     * id, whatever the list screen hid from them.
     */
    public function canAccessAttachments(User $user): bool
    {
        return app(LeadVisibility::class)->canSee($user, $this);
    }

    /**
     * Who captured this lead. ⚠ Not fillable: LeadController sets it once, from
     * the signed-in user, so a form cannot claim somebody else created it.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /* ── The type profiles (CRM slice 2c) ─────────────────────────────────────
     * ⚠ One row per type, kept when the type changes: a lead corrected from
     * retailer to distributor keeps what was learned about it as a shop. The
     * screen reads only the profile of the lead's CURRENT type. */

    public function retailerProfile(): HasOne
    {
        return $this->hasOne(RetailerLeadProfile::class);
    }

    public function distributorProfile(): HasOne
    {
        return $this->hasOne(DistributorLeadProfile::class);
    }

    public function supplierProfile(): HasOne
    {
        return $this->hasOne(SupplierLeadProfile::class);
    }

    /** Competitor brands the shop stocks, from the company's own list (answer 7). */
    public function competitorBrands(): BelongsToMany
    {
        return $this->belongsToMany(CrmLookup::class, 'lead_competitor_brands')->withPivot('business_id')->withTimestamps();
    }

    /** The thanas a distributor says it covers (answer 12: a claim). */
    public function coverageAreas(): HasMany
    {
        return $this->hasMany(LeadCoverageArea::class);
    }

    /** What the ASM saw at a distributor (answer 17). Append-only. */
    public function inspections(): HasMany
    {
        return $this->hasMany(DistributorInspection::class);
    }

    /** Samples a supplier sent, and the Owner's verdicts (answer 15). Append-only. */
    public function samples(): HasMany
    {
        return $this->hasMany(SupplierLeadSample::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'contact_name', 'phone', 'secondary_phone', 'email', 'district', 'thana', 'area', 'market', 'status', 'lead_type', 'brand_id', 'assigned_to_user_id', 'lost_reason_id', 'converted_customer_id', 'verified_at', 'archived_at'])
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
