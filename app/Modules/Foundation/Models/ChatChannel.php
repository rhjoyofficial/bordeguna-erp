<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place people talk.
 *
 * ⚠ There is no separate one-to-one thread. A private conversation is a channel
 * with two members: the same machinery, and one fewer thing to get wrong.
 */
class ChatChannel extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'topic', 'is_private', 'is_archived', 'created_by'];

    /** ⚠ The database defaults repeated, this codebase's convention. */
    protected $attributes = ['is_private' => false, 'is_archived' => false];

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'is_archived' => 'boolean'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_members')
            ->withPivot('last_read_message_id')
            ->withTimestamps();
    }

    /**
     * Channels this reader may see: every open one, plus the private ones they
     * belong to.
     *
     * ⚠⚠ A SCOPE, not a filter repeated at each call site. There are three
     * readers already - the channel list, the message list and the send action -
     * and a private channel leaking from any one of them is the same breach.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_private', false)
                ->orWhereHas('members', fn (Builder $m) => $m->where('users.id', $user->id));
        });
    }

    public function allows(User $user): bool
    {
        return ! $this->is_private || $this->members()->where('users.id', $user->id)->exists();
    }
}
