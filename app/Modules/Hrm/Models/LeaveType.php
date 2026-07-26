<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
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
class LeaveType extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    /**
     * Where ADJ-04's holiday-overtime credit lands (Correction C8b). Named as a
     * constant because payroll posts to it by code — a rename in HR's screen
     * must not silently detach the credit from its destination.
     */
    public const COMPENSATORY = 'compensatory';

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
