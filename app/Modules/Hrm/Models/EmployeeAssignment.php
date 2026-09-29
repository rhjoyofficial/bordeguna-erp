<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\EffectiveDated;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Places a (central) Employee in a business, optionally within an office /
 * department / section / designation and under a supervisor, for a period of
 * time. Business-scoped so "who works here" is a scoped query; effective-dated
 * so transfers keep history instead of overwriting.
 */
class EmployeeAssignment extends Model
{
    use BelongsToBusiness, EffectiveDated, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'office_id',
        'department_id',
        'section_id',
        'designation_id',
        'supervisor_id',
        'is_primary',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
