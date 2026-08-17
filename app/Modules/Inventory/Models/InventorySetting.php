<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-business Inventory configuration. One row, created on first use.
 */
class InventorySetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'adjustment_approval_threshold',
        'production_yield_tolerance_percent',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_approval_threshold' => 'decimal:4',
            'production_yield_tolerance_percent' => 'decimal:4',
        ];
    }

    public static function forCurrentBusiness(): self
    {
        return static::firstOrCreate([]);
    }

    /**
     * D-MFG-5: how far a production run's implied yield may deviate before it is
     * flagged. Null means the approved default of 5. Flag-only either way - the
     * tolerance never blocks a run.
     */
    public function productionYieldTolerancePercent(): string
    {
        return $this->production_yield_tolerance_percent !== null
            ? (string) $this->production_yield_tolerance_percent
            : '5';
    }

    /**
     * Whether a correction of this value may approve itself.
     *
     * ⚠ An unset threshold returns false for every value. "Not configured" means
     * "everything is reviewed", not "anything goes".
     */
    public function allowsSelfApprovalOf(?string $value): bool
    {
        if ($this->adjustment_approval_threshold === null || $value === null) {
            return false;
        }

        return (float) $value <= (float) $this->adjustment_approval_threshold;
    }
}
