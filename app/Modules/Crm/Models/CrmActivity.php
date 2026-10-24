<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
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
 * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.2): the AUTHOR may still
 * edit or delete an entry within 24 hours of typing it (a typo is not
 * evidence yet). After that a mistake is corrected: a new entry replaces it
 * and the old one stays, struck through. Counts and "last contact" read only
 * live entries (not binned, not corrected).
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
    use BelongsToBusiness, HasAttachments, Recyclable;

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
            'edited_at' => 'datetime',
            'corrected_at' => 'datetime',
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

        // ⭐ Corrections wave 3 (24 Oct 2026; FINAL-PLAN 3.2, "CRM activity:
        // author Edit or Delete within 24 hours, after that Correct"). The guard
        // stays; it now opens for exactly two things:
        //   1. the module's own correction actions (EditCrmActivity,
        //      DeleteCrmActivity, CorrectCrmActivity), which ask the policy
        //      first and run inside CrmActivity::unlocked();
        //   2. the Recycle bin's own columns (the Owner's Restore), which
        //      change nothing that was recorded.
        static::updating(function (self $activity): void {
            if (self::$unlocked > 0) {
                return;
            }

            if (array_diff(array_keys($activity->getDirty()), self::BIN_COLUMNS) === []) {
                return;
            }

            throw new RuntimeException('A CRM activity is a record of what happened and cannot be edited. Add a new activity that corrects it.');
        });

        static::deleting(function (self $activity): void {
            // ⛔ Never a real delete; the bin only, and only through the action.
            if (self::$unlocked > 0 && ! $activity->isForceDeleting()) {
                return;
            }

            throw new RuntimeException('A CRM activity is a record of what happened and cannot be deleted.');
        });
    }

    /** The columns the Recycle bin writes (deleted_at, who, why, and the touch). */
    private const BIN_COLUMNS = ['deleted_at', 'deleted_by', 'delete_reason', 'updated_at'];

    private static int $unlocked = 0;

    /**
     * Runs a sanctioned change (the 24-hour edit, the move to the bin, the
     * "corrected" mark). ⚠ Only the module's correction actions call this, and
     * each asks CrmActivityActionPolicy again under the row lock first.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function unlocked(callable $change): mixed
    {
        self::$unlocked++;

        try {
            return $change();
        } finally {
            self::$unlocked--;
        }
    }

    /** The newer activity that replaced this one (Correct). */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /** The activity this one corrects. */
    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }

    public function isCorrected(): bool
    {
        return $this->corrected_at !== null;
    }

    /**
     * Still in the author's 24 hours (FINAL-PLAN 3.2), counted from when it was
     * TYPED, not from when it happened: a call written up a day late is still
     * fresh for its author to fix.
     */
    public function isFresh(): bool
    {
        return $this->created_at !== null && $this->created_at->gt(now()->subDay());
    }

    /** The Recycle bin's line: "Call on 24/10/2026: Asked for the price list". */
    public function binLabel(): string
    {
        return $this->type->label().' on '.($this->occurred_at?->format('d/m/Y') ?? '?').': '.$this->summary;
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
            // ⭐ Wave 3: a corrected visit hands its place to its correction.
            ->whereNull('corrected_at')
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
