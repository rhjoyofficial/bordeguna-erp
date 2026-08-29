<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody put in the calendar by hand.
 *
 * ⚠ Everything else the calendar shows comes from the module that owns it. See
 * CalendarFeed.
 */
class CalendarEvent extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'title', 'description', 'location',
        'starts_at', 'ends_at', 'all_day', 'is_private', 'created_by',
    ];

    /** ⚠ The database defaults repeated, the convention this codebase follows. */
    protected $attributes = ['all_day' => true, 'is_private' => false];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'is_private' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Events this user is allowed to see: everything shared, plus their own
     * private ones.
     *
     * ⚠⚠ A scope rather than a filter written at each call site. There are three
     * readers already (the month, the day, the edit form) and a private event
     * leaking from any one of them is the same breach.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_private', false)->orWhere('created_by', $user->id));
    }

    /**
     * ⚠ An event running from Monday to Wednesday belongs on all three days.
     * Asking only whether it STARTS in the window would drop a week-long event
     * from every week but its first.
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_at', '<=', $to.' 23:59:59')
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from.' 00:00:00'));
    }
}
