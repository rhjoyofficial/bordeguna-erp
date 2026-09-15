<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Something that actually happened with a lead or a customer.
 *
 * ⚠⚠ APPEND-ONLY. A note recording what somebody said on a call is EVIDENCE.
 * Editing it later - or quietly deleting it - is how a CRM stops being worth
 * trusting, and by then nobody can tell which entries were changed. A correction
 * is a NEW activity that refers to the mistake, never an edit of the original.
 * Same guard as CrmAttribution, for the same reason.
 *
 * ⭐ A VISIT MAY CARRY PROOF (CRM slice 2d, Joy's answer 2): a location from the
 * officer's phone and photos, both optional. They are written with the visit
 * and, like everything else here, never changed afterwards.
 *
 * ⚠⚠ AN ACTIVITY ON A LEAD IS VISIBLE ONLY WHERE THE LEAD IS. The global scope
 * below runs the lead's own visibility scope, so a visit's photos cannot be
 * listed in the file manager, or streamed, by somebody outside the lead's team.
 * Activities on a customer keep the old rule: anyone who may open the timeline.
 *
 * ⛔ THIS IS NOT THE AUDIT LOG. Spatie's ActivityLogEntry records which COLUMN
 * changed; this records what a human did with another human. Repurposing either
 * as the other loses both.
 */
class CrmActivity extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments;

    public const PHOTOS = 'photos';

    /** The only things an activity may be about. C-CRMB-4. */
    public const SUBJECTS = [Lead::class, Customer::class];

    protected $fillable = [
        'business_id',
        'subject_type',
        'subject_id',
        'type',
        'direction',
        'occurred_at',
        'summary',
        'body',
        'outcome',
        'duration_seconds',
        'latitude',
        'longitude',
        'gps_accuracy_meters',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'direction' => ActivityDirection::class,
            'occurred_at' => 'datetime',
            'duration_seconds' => 'integer',
            // ⚠ decimal, not float: SQLite and MySQL then return the same string.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_meters' => 'decimal:2',
        ];
    }

    /**
     * ⚠⚠ The guard that makes "append-only" true rather than merely intended.
     * Enforced on the MODEL so every path - controller, action, tinker, a future
     * import - meets it, instead of on one route that a second caller bypasses.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_lead', function (Builder $query): void {
            $model = $query->getModel();

            $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('subject_type'), '!=', (new Lead)->getMorphClass())
                ->orWhereIn($model->qualifyColumn('subject_id'), Lead::query()->select('leads.id')));
        });

        static::updating(function (): never {
            throw new RuntimeException('A CRM activity is a record of what happened and cannot be edited. Add a new activity that corrects it.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('A CRM activity is a record of what happened and cannot be deleted.');
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * The visits of a subject that carry a location, first one first. Plan D14:
     * the FIRST of them is the shop's pinned location.
     *
     * ⚠ By when the visit HAPPENED, not when it was typed: a visit written up a
     * day late is still the first time somebody stood at the shop.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLocatedVisitsOf(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('type', ActivityType::Visit)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function canAccessAttachments(User $user): bool
    {
        if ($this->subject_type === (new Lead)->getMorphClass()) {
            $lead = Lead::withoutGlobalScopes()->find($this->subject_id);

            return $lead !== null && $lead->canAccessAttachments($user);
        }

        // A customer's timeline is open to whoever may read leads.
        return $user->can('crm.leads.view');
    }

    /** "Call · We contacted them" */
    public function label(): string
    {
        return $this->direction === null
            ? $this->type->label()
            : $this->type->label().' · '.$this->direction->label();
    }
}
