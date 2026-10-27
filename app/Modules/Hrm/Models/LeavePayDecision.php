<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ 27 Oct 2026 (Joy): one pay decision on an approved leave, kept as history.
 * Append-only; the request's `unpaid_days` is the current answer, these rows
 * say how it got there (DecideLeavePay).
 */
class LeavePayDecision extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'leave_request_id',
        'unpaid_days_before',
        'unpaid_days_after',
        'quota_days',
        'granted_days',
        'at_approval',
        'reason',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'unpaid_days_before' => 'decimal:2',
            'unpaid_days_after' => 'decimal:2',
            'quota_days' => 'decimal:2',
            'granted_days' => 'decimal:2',
            'at_approval' => 'boolean',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
