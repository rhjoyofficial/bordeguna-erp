<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody wrote down.
 *
 * ⚠ The visibility rule is identical to CalendarEvent's on purpose. Two screens
 * that both hold private rows and disagree about what private means is how a
 * leak happens on the quieter of the two.
 */
class Note extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'title', 'body', 'is_private', 'is_pinned', 'remind_on', 'archived_at', 'created_by',
    ];

    /** ⚠ The database defaults repeated, the convention this codebase follows. */
    protected $attributes = ['is_private' => false, 'is_pinned' => false];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'is_pinned' => 'boolean',
            'remind_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everything shared, plus this reader's own private notes.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_private', false)->orWhere('created_by', $user->id));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
