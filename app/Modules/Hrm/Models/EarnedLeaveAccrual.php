<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's earned-leave decision for one month: granted, or withheld
 * with the counts it was refused on.
 */
class EarnedLeaveAccrual extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'year',
        'month',
        'days_granted',
        'withheld_reason',
        'ordinary_lates',
        'serious_lates',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'days_granted' => 'decimal:2',
            'ordinary_lates' => 'integer',
            'serious_lates' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function wasWithheld(): bool
    {
        return $this->withheld_reason !== null;
    }

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }
}
