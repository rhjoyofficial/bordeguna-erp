<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Exceptions\PeriodLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The date before which inventory is closed for this business (C-SC-2).
 *
 * One row per business: the question is always "what is the earliest date I may
 * still write to?", and a single moving boundary answers it without a table that
 * grows forever or gaps between periods that nobody notices.
 */
class InventoryPeriodLock extends Model
{
    use BelongsToBusiness;

    private const LOG = 'inventory.period_lock';

    protected $fillable = [
        'business_id',
        'closed_through',
        'closed_by',
        'note',
        'is_unlocked',
        'unlocked_by',
        'unlocked_at',
        'unlock_note',
    ];

    protected function casts(): array
    {
        return [
            'closed_through' => 'date',
            'is_unlocked' => 'boolean',
            'unlocked_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * Closes everything up to and including `$through`.
     */
    public static function closeThrough(Carbon $through, User $by, ?string $note = null): self
    {
        $lock = static::current();

        if ($lock === null) {
            $lock = static::create([
                'closed_through' => $through,
                'closed_by' => $by->id,
                'note' => $note,
            ]);
        } else {
            $lock->update([
                'closed_through' => $through,
                'closed_by' => $by->id,
                'note' => $note,
                'is_unlocked' => false,
                'unlocked_by' => null,
                'unlocked_at' => null,
                'unlock_note' => null,
            ]);
        }

        activity(self::LOG)
            ->causedBy($by)
            ->withProperties(['closed_through' => $through->toDateString(), 'note' => $note])
            ->log("Closed inventory through {$through->toDateString()}");

        return $lock->refresh();
    }

    /**
     * ⚠ Temporary by design, and Founder-only at the call site. The unlock is an
     * event with a reason attached rather than a setting somebody flips, because
     * a quiet boolean is how a "temporary" exception becomes permanent.
     */
    public function unlock(User $by, string $reason): void
    {
        $this->update([
            'is_unlocked' => true,
            'unlocked_by' => $by->id,
            'unlocked_at' => now(),
            'unlock_note' => $reason,
        ]);

        activity(self::LOG)
            ->causedBy($by)
            ->withProperties(['closed_through' => $this->closed_through->toDateString(), 'note' => $reason])
            ->log("Unlocked the period closed through {$this->closed_through->toDateString()}");
    }

    public function relock(User $by): void
    {
        $this->update([
            'is_unlocked' => false,
            'unlocked_by' => null,
            'unlocked_at' => null,
            'unlock_note' => null,
        ]);

        activity(self::LOG)
            ->causedBy($by)
            ->withProperties(['closed_through' => $this->closed_through->toDateString()])
            ->log("Relocked the period closed through {$this->closed_through->toDateString()}");
    }

    /**
     * ⚠ Refuses rather than returns a boolean, because a caller that forgets to
     * check a boolean writes into a closed period and nothing notices. There is
     * one way to ask this question and it throws.
     */
    public static function assertOpenFor(Carbon $date): void
    {
        $lock = static::current();

        if ($lock === null || $lock->is_unlocked) {
            return;
        }

        // Inclusive: "closed through the 31st" closes the 31st.
        if ($date->lessThanOrEqualTo($lock->closed_through->endOfDay())) {
            throw PeriodLockedException::forDate($date, $lock->closed_through);
        }
    }
}
