<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which library sound each event plays (14 Oct 2026): success, attention and
 * error on the dashboards, popup for a popup that uses the default, schedule
 * for a work schedule alert (built-in success sound when null). A null
 * sound_id means the built-in file (public/sounds) or, for popup, silence.
 */
class SoundSlot extends Model
{
    public const SLOTS = ['success', 'attention', 'error', 'popup', 'schedule'];

    protected $primaryKey = 'slot';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['slot', 'sound_id', 'updated_by'];

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
