<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One calculation attempt at a payroll period, versioned per WFL-06.
 *
 * The full WFL-02 lifecycle (Draft → Calculated → Reviewed → Approved →
 * Disbursed → Locked), its approval routing and the queued calculation arrive
 * in Phase 3c. This slice carries only the two statuses it can honestly
 * support, rather than declaring transitions nothing enforces yet.
 */
class PayrollRun extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    protected $fillable = [
        'business_id',
        'payroll_period_id',
        'version',
        'status',
        'config',
        'employee_count',
        'blocked_count',
        'total_earnings',
        'total_deductions',
        'total_net',
        'calculated_at',
        'calculated_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'config' => 'array',
            'employee_count' => 'integer',
            'blocked_count' => 'integer',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['version', 'status', 'employee_count', 'blocked_count', 'total_net', 'calculated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    /**
     * WFL-05 allows a free re-run only while a payroll is still Draft or
     * Calculated. Anything further needs the reopen approval Phase 3c builds.
     */
    public function isRerunnable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CALCULATED], true);
    }

    public function hasBlockedLines(): bool
    {
        return $this->blocked_count > 0;
    }
}
