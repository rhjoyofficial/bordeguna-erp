<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something somebody intends to do about a lead or a customer.
 *
 * ⚠⚠ MUTABLE ON PURPOSE, unlike CrmActivity. A follow-up gets rescheduled,
 * reassigned, done or abandoned - that lifecycle IS the feature. History and
 * intention are different things and live in different tables.
 *
 * ⚠ Field-level changes ARE audited (LogsActivity), so a task quietly moved to
 * next month leaves a trace. That is the correct use of the audit log; it is not
 * a substitute for a CRM activity.
 */
class CrmTask extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const SUBJECTS = [Lead::class, Customer::class];

    protected $fillable = [
        'business_id',
        'subject_type',
        'subject_id',
        'title',
        'note',
        'due_at',
        'assigned_to_user_id',
        'status',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    /**
     * ⚠ Mirrors the database default. Without it a freshly built task carries
     * null for status in memory while the stored row is `open`, and a guard
     * reading `$task->status->isClosed()` fatals on null. This project has met
     * that bug on money_accounts, LeadSource and businesses.
     */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'due_at', 'assigned_to_user_id', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Open);
    }

    /**
     * ⚠ Overdue is asked of OPEN tasks only. A task completed late is not
     * overdue, it is done - and a queue that keeps shouting about finished work
     * is a queue people stop reading.
     */
    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->status === TaskStatus::Open
            && $this->due_at !== null
            && $this->due_at->isBefore($now ?? now());
    }
}
