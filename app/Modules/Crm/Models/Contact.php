<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One person in the phone book.
 *
 * ⚠⚠ THE NUMBER THE HUMAN TYPED IS NEVER REWRITTEN. `phone` keeps it exactly as
 * entered; `phone_normalized` is a separate matching form maintained here so no
 * caller can forget to set it. Joy's rule from the supplier decision stands: we
 * do not silently correct somebody's data.
 *
 * ⚠ A contact may point back at a lead or a customer it was pulled from. That
 * link is informational: the master record stays the master, and this row exists
 * so there is ONE place that holds an addressable person, whether or not they
 * are anything else to the business.
 */
class Contact extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'name', 'company', 'phone', 'phone_normalized', 'email',
        'address', 'source', 'note', 'linked_type', 'linked_id', 'is_active', 'created_by',
    ];

    /**
     * ⚠ The database default repeated on the model. A column default only
     * applies once the row is written, so a fresh instance would carry
     * `is_active` NULL and a caller checking `! $contact->is_active` would treat
     * an active contact as inactive. This has already bitten Customer and
     * LeadSource in this codebase.
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * ⚠⚠ Normalisation lives in the model, not in the controller or the
     * importer. There are three ways a contact is created - the screen, the CSV
     * import, and being pulled in from CRM - and a matching column that only two
     * of them maintain is worse than no matching column, because the search
     * quietly misses exactly the rows the third path created.
     */
    protected static function booted(): void
    {
        static::saving(function (self $contact) {
            $contact->phone_normalized = PhoneIdentity::normalize($contact->phone);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'company', 'phone', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_group_members')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Where this contact came from, in words, for a screen to show.
     */
    public function linkedLabel(): ?string
    {
        return match ($this->linked_type) {
            Lead::class => 'Lead',
            Customer::class => 'Customer',
            default => null,
        };
    }

    /**
     * ⚠ Contacts that look like the same person as this one.
     *
     * WARNS, never blocks. A shared household number is normal and refusing the
     * second entry would lose a real person to protect a tidy table.
     */
    public function possibleDuplicates(): Builder
    {
        return static::query()
            ->where('id', '!=', $this->id ?? 0)
            ->when(
                $this->phone_normalized !== null,
                fn (Builder $q) => $q->where('phone_normalized', $this->phone_normalized),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            );
    }
}
