<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component's figure within a structure. `value` is an amount when the
 * component's basis is Fixed and a percentage otherwise — the component says
 * which, so the number is never ambiguous on its own.
 *
 * Tenant scoping is inherited through the structure, which is business-scoped.
 */
class SalaryStructureLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_structure_id',
        'salary_component_id',
        'value',
    ];

    protected function casts(): array
    {
        // 4 places because this holds a percentage for non-fixed components;
        // the resolved money amount is rounded to 2 (PAY-10).
        return ['value' => 'decimal:4'];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
