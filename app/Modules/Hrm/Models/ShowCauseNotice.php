<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A show cause notice - the formal letter that asks an employee to explain
 * repeated lateness (or anything else HR must put on record).
 *
 * Immutable once issued: no update, no delete, no routes for either. The row
 * IS the record - who issued it, to whom, when, and the exact wording - which
 * is why it carries no LogsActivity: an activity trail of an unchangeable row
 * would only restate the row.
 */
class ShowCauseNotice extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'issued_by',
        'subject',
        'body',
        'context',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
