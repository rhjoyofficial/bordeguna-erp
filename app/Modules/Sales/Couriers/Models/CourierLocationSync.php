<?php

namespace App\Modules\Sales\Couriers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of "fetch this courier's lists". A run that did not finish is
 * resumed by the next one rather than started over.
 */
class CourierLocationSync extends Model
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'provider', 'courier_account_id', 'status', 'calls', 'cities', 'zones', 'areas',
        'last_error', 'started_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'calls' => 'integer',
            'cities' => 'integer',
            'zones' => 'integer',
            'areas' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }
}
