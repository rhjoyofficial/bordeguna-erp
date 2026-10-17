<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * "I saw the warning and this is a different record", kept beside the saved
 * record (PHASE-0-CONTRACT 1.11). Append-only evidence; never Recyclable.
 */
class DuplicateAcknowledgement extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['candidates' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A duplicate acknowledgement is never changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('A duplicate acknowledgement is never deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
