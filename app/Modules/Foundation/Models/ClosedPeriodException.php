<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Owner exception for ONE document in a closed month (PHASE-0-CONTRACT v2,
 * 1.10). Written, consumed and closed inside the correction's own transaction.
 *
 * ⚠⚠ PERMANENT EVIDENCE. `used` means "can never be used again", not "gone".
 * Nothing deletes a row, and the model refuses a delete. It is never Recyclable.
 *
 * ⚠ Only ClosedPeriodExceptions writes it (grant, consume, close); there is no
 * fillable list on purpose.
 */
class ClosedPeriodException extends Model
{
    use BelongsToBusiness;

    public const GRANTED = 'granted';

    public const USED = 'used';

    protected $table = 'closed_period_exceptions';

    protected $guarded = ['*'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'closed_through_snapshot' => 'date:Y-m-d',
            'dates' => 'array',
            'max_journals' => 'integer',
            'journals_posted' => 'integer',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A closed-month exception is evidence and is never deleted.');
        });
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function isUsed(): bool
    {
        return $this->status === self::USED;
    }
}
