<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A request to reopen a locked payroll (WFL-08).
 *
 * Its own approvable record rather than a flag, because WFL-08 asks for three
 * separate authorities — HR Head, Finance and Management — and the existing
 * multi-stage workflow engine already models exactly that. The stages are
 * defined by **permission**, not role, because those roles do not exist in this
 * system and inventing them would be an architectural assumption rather than a
 * business decision (Joy's call, 2026-07-25).
 *
 * The Super Admin emergency path WFL-08 allows is available too, but never
 * silently: it records that the chain was bypassed and why.
 */
class PayrollUnlockRequest extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'payroll_run_id',
        'reason',
        'is_emergency_override',
        'override_reason',
        'requested_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'is_emergency_override' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payroll_run_id', 'reason', 'is_emergency_override', 'override_reason', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvalWorkflowKey(): string
    {
        return 'payroll_unlock';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function summaryLine(): string
    {
        return sprintf(
            'Reopen %s payroll (v%d) — %s',
            $this->run?->period?->label() ?? 'locked',
            $this->run?->version ?? 0,
            $this->reason,
        );
    }
}
