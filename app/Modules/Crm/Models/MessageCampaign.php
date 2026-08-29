<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One outbound send.
 *
 * ⚠⚠ NOT the `campaigns` table. That one records where a LEAD CAME FROM. This
 * is money going out. Same word, opposite direction.
 *
 * ⚠⚠ Approvable, and its workflow row ships in the same commit as the code
 * (`MessageCampaignWorkflowSeeder`) with a case in the approval inbox. That is
 * not politeness: a module whose approvals reach nobody is exactly how expense
 * claims shipped dead, and `EveryApprovalReachesSomebodyTest` now fails the
 * build if either is missing.
 */
class MessageCampaign extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'channel', 'name', 'subject', 'body',
        'audience_type', 'contact_group_id', 'message_provider_id',
        'recipient_cap', 'status', 'estimated_recipients', 'estimated_parts',
        'estimated_cost', 'sent_at', 'created_by',
    ];

    /** ⚠ The database defaults repeated, this codebase's convention. */
    protected $attributes = ['status' => 'draft', 'audience_type' => 'group', 'recipient_cap' => 500];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'estimated_cost' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'estimated_recipients', 'estimated_cost'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MessageDelivery::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(MessageProvider::class, 'message_provider_id');
    }
}
