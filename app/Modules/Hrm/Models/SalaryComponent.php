<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\SalaryComponentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A reusable earning or deduction definition (SAL-02/03/04).
 *
 * The definition is shared; the figure is per employee. A payslip line can
 * therefore always name the component it came from, which is half of what
 * PAY-13's explainability requirement asks for.
 */
class SalaryComponent extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'type',
        'is_basic',
        'calculation_basis',
        'sequence',
        'is_statutory',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calculation_basis' => SalaryCalculationBasis::class,
            'is_basic' => 'boolean',
            'is_statutory' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'type', 'is_basic', 'calculation_basis', 'sequence', 'is_statutory', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isEarning(): bool
    {
        return $this->type === SalaryComponentType::Earning;
    }

    public function isDeduction(): bool
    {
        return $this->type === SalaryComponentType::Deduction;
    }

    /**
     * The single Basic component for the current business, if one is defined.
     */
    public static function basic(): ?self
    {
        return static::where('is_basic', true)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEarnings(Builder $query): Builder
    {
        return $query->where('type', SalaryComponentType::Earning);
    }

    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('type', SalaryComponentType::Deduction);
    }

    /**
     * Ordered the way a payslip reads: Basic first, then the rest of the
     * earnings, then deductions — each group by its own sequence.
     */
    public function scopeInPayslipOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_basic')->orderBy('type')->orderBy('sequence')->orderBy('name');
    }
}
