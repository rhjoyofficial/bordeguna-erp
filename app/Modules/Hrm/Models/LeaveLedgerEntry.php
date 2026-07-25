<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of a leave balance. Append-only: a balance is the sum of these,
 * never a stored figure, so every number can be explained back to the events
 * that produced it.
 *
 * `source_key` carries idempotency for anything that must happen exactly once
 * per employee/type/year — the annual grant and the year-end carry-forward and
 * lapse. Posting through `once()` makes a rerun a no-op rather than a
 * duplicate, which is what lets the year-end job be safely repeated.
 */
class LeaveLedgerEntry extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'leave_type_id',
        'year',
        'entry_type',
        'days',
        'occurred_on',
        'note',
        'reference_type',
        'reference_id',
        'source_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entry_type' => LeaveLedgerEntryType::class,
            'days' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Post an entry at most once for a given key. The unique index on
     * (business_id, source_key) is the real guarantee; this is the read that
     * keeps a rerun quiet rather than throwing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function once(string $sourceKey, array $attributes): ?self
    {
        if (static::where('source_key', $sourceKey)->exists()) {
            return null;
        }

        return static::create([...$attributes, 'source_key' => $sourceKey]);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }
}
