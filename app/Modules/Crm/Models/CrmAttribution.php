<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One touch in an acquisition history: this person came to us through this
 * source, on this campaign, at this moment.
 *
 * ⚠⚠ POLYMORPHIC OVER LEAD **AND** CUSTOMER (C-CRM-4). Joy's requirement is that
 * an existing customer responding to a later campaign is recorded WITHOUT
 * creating a second customer. A lead-only attribution table cannot express that
 * without inventing a fake lead for somebody who is already a customer.
 *
 * ⚠⚠ APPEND-ONLY, AND ENFORCED RATHER THAN ASSUMED (C-CRM-7). "Preserve the
 * entire acquisition history, not merely first and latest" is the requirement,
 * and a table that can be updated does not preserve anything. First and latest
 * are DERIVED from these rows and cached nowhere (C-CRM-6): a stored copy of a
 * derivable fact is a copy that goes stale, which this project has already been
 * bitten by once with a cached stock quantity written by two hands.
 */
class CrmAttribution extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'subject_type', 'subject_id', 'lead_source_id', 'campaign_id',
        'ad_set', 'ad_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'external_lead_id', 'payload', 'occurred_at', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * ⚠⚠ THE APPEND-ONLY GUARD. Model events rather than a convention in a
     * comment, because a convention is a thing somebody has not read yet.
     *
     * ⚠ `updating` and `deleting` both throw. An attribution that can be edited
     * would let today's correction silently rewrite what a campaign appeared to
     * produce last March - the same class of retrospective rewrite that the
     * conversion factor on a stock batch exists to prevent.
     *
     * ⚠ A mistake is corrected by recording another touch, not by editing the
     * one that was wrong. The history is the point.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'A CRM attribution is append-only and cannot be edited. Record a new attribution instead.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'A CRM attribution is append-only and cannot be deleted. It is the acquisition history.'
            );
        });
    }

    /** The lead or customer this touch belongs to. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
