<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kind of leave (LEV-01) and the rules that apply to it.
 *
 * Correction C6c put carry-forward and encashment here rather than applying one
 * rule to all seven types, and C6b's cap likewise — both are HR-editable data,
 * not constants.
 */
class LeaveType extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, LogsActivity, Recyclable;

    /**
     * Where ADJ-04's holiday-overtime credit lands (Correction C8b). Named as a
     * constant because payroll posts to it by code — a rename in HR's screen
     * must not silently detach the credit from its destination.
     */
    public const COMPENSATORY = 'compensatory';

    /**
     * Codes the engine addresses directly (the unpaid fallback, the
     * holiday-overtime credit): never switched off, never deleted.
     */
    public const SYSTEM_CODES = ['unpaid', self::COMPENSATORY];

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'annual_quota',
        'is_paid',
        'deducts_balance',
        'allows_half_day',
        'can_carry_forward',
        'can_encash',
        'carry_forward_cap',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'decimal:2',
            'is_paid' => 'boolean',
            'deducts_balance' => 'boolean',
            'allows_half_day' => 'boolean',
            'can_carry_forward' => 'boolean',
            'can_encash' => 'boolean',
            'carry_forward_cap' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'code', 'annual_quota', 'is_paid', 'deducts_balance', 'allows_half_day',
                'can_carry_forward', 'can_encash', 'carry_forward_cap', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Correction C6b: the ceiling on accumulated balance. Defaults to twice the
     * annual quota when HR has not set one — a bounded default rather than the
     * unbounded accumulation LEV-03's literal answer implied.
     */
    public function effectiveCarryForwardCap(): float
    {
        return (float) ($this->carry_forward_cap ?? ((float) $this->annual_quota * 2));
    }

    /**
     * The unpaid type is where LEV-07 sends a request with no balance behind it.
     */
    public static function unpaid(): ?self
    {
        return static::where('code', 'unpaid')->first();
    }

    public function isSystem(): bool
    {
        return in_array($this->code, self::SYSTEM_CODES, true);
    }

    /**
     * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): Delete to the Recycle
     * bin only while nothing points at it. Leave requests and the leave ledger
     * point at a type by id; a system code is addressed by the engine.
     */
    public function whyNotRemovable(): ?string
    {
        if ($this->isSystem()) {
            return "{$this->name} is where the system sends leave by itself, so it stays.";
        }

        $requests = LeaveRequest::query()->where('leave_type_id', $this->id)->count();
        $entries = LeaveLedgerEntry::query()->where('leave_type_id', $this->id)->count();

        if ($requests + $entries === 0) {
            return null;
        }

        $uses = array_filter([
            $requests > 0 ? $requests.' leave '.($requests === 1 ? 'request' : 'requests') : null,
            $entries > 0 ? $entries.' leave balance '.($entries === 1 ? 'entry' : 'entries') : null,
        ]);

        return "{$this->name} is used (".implode(', ', $uses).'), so it stays. Switch it off instead.';
    }

    public function whyNotDeactivatable(): ?string
    {
        return $this->isSystem() ? "{$this->name} is where the system sends leave by itself, so it cannot be switched off." : null;
    }

    public function binLabel(): string
    {
        return "Leave type {$this->name} ({$this->code})";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
