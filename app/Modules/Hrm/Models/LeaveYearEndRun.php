<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of one year-end carry-forward run (Correction C6b/C6d).
 *
 * Previews are recorded too, marked as dry runs, so "what would have happened"
 * is auditable in its own right — HR can point at the projection they reviewed
 * before committing, and compare it with what was actually posted.
 */
class LeaveYearEndRun extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'year',
        'is_dry_run',
        'plan',
        'employees_affected',
        'total_carried',
        'total_lapsed',
        'run_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_dry_run' => 'boolean',
            'plan' => 'array',
            'employees_affected' => 'integer',
            'total_carried' => 'decimal:2',
            'total_lapsed' => 'decimal:2',
        ];
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
