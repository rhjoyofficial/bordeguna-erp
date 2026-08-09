<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Customer;
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
 * ⛔ THIS IS NOT THE AUDIT LOG. Spatie's ActivityLogEntry records which COLUMN
 * changed; this records what a human did with another human. Repurposing either
 * as the other loses both.
 */
class CrmActivity extends Model
{
    use BelongsToBusiness;

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
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'direction' => ActivityDirection::class,
            'occurred_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * ⚠⚠ The guard that makes "append-only" true rather than merely intended.
     * Enforced on the MODEL so every path - controller, action, tinker, a future
     * import - meets it, instead of on one route that a second caller bypasses.
     */
    protected static function booted(): void
    {
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

    /** "Call · We contacted them" */
    public function label(): string
    {
        return $this->direction === null
            ? $this->type->label()
            : $this->type->label().' · '.$this->direction->label();
    }
}
