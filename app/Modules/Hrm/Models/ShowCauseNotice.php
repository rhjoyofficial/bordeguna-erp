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
 * Immutable once issued: no edit, no delete. The row IS the record - who
 * issued it, to whom, when, and the exact wording.
 *
 * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): it can be WITHDRAWN,
 * with a date and a reason. The wording never changes and the row stays; it
 * is marked Withdrawn (the corrections log keeps who, when and why). The
 * employee sees the withdrawal only if the letter reached them (`notified_at`);
 * one they never received is simply gone from their list.
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
        'notified_at',
        'withdrawn_on',
        'withdrawn_at',
        'withdrawn_by',
        'withdraw_reason',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'issued_at' => 'datetime',
            'notified_at' => 'datetime',
            'withdrawn_on' => 'date:Y-m-d',
            'withdrawn_at' => 'datetime',
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

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /** The letter reached the employee (their bell rang when it was issued). */
    public function wasDelivered(): bool
    {
        return $this->notified_at !== null;
    }
}
