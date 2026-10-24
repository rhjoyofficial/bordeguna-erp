<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
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
class Contact extends Model implements Removable
{
    use BelongsToBusiness, LogsActivity, Recyclable;

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026): the bin writes its own History line
     * with the reason, so the model log keeps to creating and changing.
     *
     * @var list<string>
     */
    protected static $recordEvents = ['created', 'updated'];

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

    /**
     * ⚠ Bug hunt 16 Oct 2026 (03 C5, decided 17 Oct): a contact pulled from a
     * lead is that lead's phone and address, so it is seen by whoever may see
     * the lead, under the lead list's own rule (own and team, or everything
     * with crm.leads.view.all). Lead's global LeadVisibilityScope applies that
     * rule to the subquery; every other contact is untouched.
     */
    public function scopeVisibleLeadsOnly(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $q) => $q
            ->whereNull("{$table}.linked_type")
            ->orWhere("{$table}.linked_type", '!=', Lead::class)
            ->orWhereIn("{$table}.linked_id", Lead::query()->select('leads.id')));
    }

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026; FINAL-PLAN 6.4): a contact goes to the
     * Recycle bin only when no message was ever sent to it. A message sent is
     * something that left the company, so that contact is switched off
     * instead, and the delivery list keeps naming it.
     *
     * ⚠ Group membership is not "use": the rows stay, a binned contact drops
     * out of every group and every send by itself (the bin's scope), and a
     * restore brings it back into the same groups.
     */
    public function whyNotRemovable(): ?string
    {
        return DB::table('message_deliveries')->where('contact_id', $this->id)->exists()
            ? 'Messages were sent to this contact. Switch it off instead.'
            : null;
    }

    public function whyNotDeactivatable(): ?string
    {
        return null;
    }

    /** The Recycle bin's line: "Contact Rahim (01711000000)". */
    public function binLabel(): string
    {
        return 'Contact '.$this->name.(filled($this->phone) ? " ({$this->phone})" : '');
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
