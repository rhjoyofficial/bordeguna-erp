<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded decision on one stage of an approval workflow. Every stage's
 * decision is kept separately so a multi-stage approval has a full per-stage
 * trail, not just the final outcome.
 */
class ApprovalDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_request_id',
        'stage_sequence',
        'stage_name',
        'decided_by',
        'decision',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'stage_sequence' => 'integer',
            'decision' => ApprovalStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
