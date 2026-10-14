<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The claim that a once-a-day or once-per-person popup was shown to someone.
 * The unique (popup, user, period) key is the whole race guard: two tabs
 * claim, one insert wins, the other learns it lost and stays quiet.
 */
class PopupView extends Model
{
    public $timestamps = false;

    protected $fillable = ['popup_id', 'user_id', 'period', 'shown_at'];

    protected function casts(): array
    {
        return ['shown_at' => 'datetime'];
    }
}
