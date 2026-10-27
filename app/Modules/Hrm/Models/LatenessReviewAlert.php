<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable record that HR was alerted: this employee has reached the last
 * FREE occurrence of a lateness window this month - one more and the ladder
 * bites. One row per employee + window + payroll cycle; the unique index is
 * the real idempotency guarantee, and it is what keeps a force-rebuild of the
 * month from ringing the same bell twice.
 */
class LatenessReviewAlert extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'grace_window_key',
        'year',
        'month',
        'occurrence_number',
        'alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'occurrence_number' => 'integer',
            'alerted_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
