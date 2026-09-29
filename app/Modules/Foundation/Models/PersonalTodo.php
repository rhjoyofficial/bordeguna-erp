<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's private list of things to do.
 *
 * ⚠⚠ EVERY QUERY MUST BE OWNER-SCOPED, and `mine()` is the only way this model
 * should ever be read. There is no relationship from a manager to a team's
 * lists, no `assigned_to`, and no scope that returns anybody else's rows. A
 * to-do list people suspect their manager reads is one nobody writes honestly.
 */
class PersonalTodo extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'title', 'notes', 'due_on', 'completed_at'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ⚠⚠ The owner comes from the caller's own session, never from a request
     * parameter. There is deliberately no way to name somebody else.
     */
    public function scopeMine(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
