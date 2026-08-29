<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody said.
 *
 * ⚠⚠ DELETING IS A TIMESTAMP, NOT A REMOVAL. A message that vanishes without
 * trace turns every later disagreement about what was said into one person's
 * word against another's. The screen shows "this message was removed" and the
 * row stays.
 */
class ChatMessage extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'chat_channel_id', 'user_id', 'body', 'edited_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isRemoved(): bool
    {
        return $this->deleted_at !== null;
    }
}
