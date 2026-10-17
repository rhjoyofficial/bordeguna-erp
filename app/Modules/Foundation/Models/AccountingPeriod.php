<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The date before which the books are closed for this business.
 *
 * ⚠⚠ Built for Inventory (C-SC-2) and generalised in Phase 1.2 of Accounting,
 * because a closed month has to mean the same thing to a salary payment, a
 * supplier bill and a journal as it does to a goods receipt. The alternative was
 * a second lock that could disagree with the first.
 *
 * One row per business: the question is always "what is the earliest date I may
 * still write to?", and a single moving boundary answers it without a table that
 * grows forever or gaps between periods that nobody notices.
 */
class AccountingPeriod extends Model
{
    use BelongsToBusiness;

    private const LOG = 'accounting.period';

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
            'closed_through' => 'date:Y-m-d',
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
            ->log("Closed the books through {$through->toDateString()}");

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
     * The first day on or after `$date` the books are open for (courier hub
     * slice 8, ChatGPT risk 9): the date itself when it is open, otherwise the
     * day after the closed boundary. A courier's answer or statement that
     * arrives after its month was closed posts here, never into the locked
     * month. Refused only when even that day has not happened yet.
     */
    public static function firstOpenOnOrAfter(Carbon $date): Carbon
    {
        $lock = static::current();
        $date = $date->copy()->startOfDay();

        if ($lock === null || $lock->is_unlocked || $date->gt($lock->closed_through->copy()->endOfDay())) {
            return $date;
        }

        $open = $lock->closed_through->copy()->addDay()->startOfDay();

        if ($open->isFuture()) {
            throw PeriodClosedException::forDate($date, $lock->closed_through);
        }

        return $open;
    }

    /**
     * ⚠ Refuses rather than returns a boolean, because a caller that forgets to
     * check a boolean writes into a closed period and nothing notices. There is
     * one way to ask this question and it throws.
     */
    public static function assertOpenFor(Carbon $date, ?ClosedPeriodPass $pass = null): void
    {
        $lock = static::current();

        if ($lock === null || $lock->is_unlocked) {
            return;
        }

        // Inclusive: "closed through the 31st" closes the 31st.
        if ($date->lessThanOrEqualTo($lock->closed_through->endOfDay())) {
            // ⚠⚠ The Owner's one-document exception (edit, delete and correct,
            // Phase 0, 17 Oct 2026): the boundary and is_unlocked stay exactly
            // as they are; a pass that names THIS date lets THIS call through.
            // Whether the pass may really post is decided when PostJournal
            // consumes it (ClosedPeriodExceptions::consume).
            if ($pass !== null && $pass->covers($date, (int) $lock->business_id)) {
                return;
            }

            throw PeriodClosedException::forDate($date, $lock->closed_through);
        }
    }

    /** Whether `$date` falls inside the closed range right now. */
    public static function isClosedOn(Carbon $date): bool
    {
        $lock = static::current();

        return $lock !== null && ! $lock->is_unlocked && $date->lessThanOrEqualTo($lock->closed_through->copy()->endOfDay());
    }
}
