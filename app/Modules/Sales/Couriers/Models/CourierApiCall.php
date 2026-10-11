<?php

namespace App\Modules\Sales\Couriers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request the ERP made to a courier: where, how long, what came back.
 *
 * ⚠⚠ NEVER a secret, a header or a request body. The path, the status and a
 * short error with every known secret value scrubbed out.
 */
class CourierApiCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['courier_account_id', 'method', 'endpoint', 'http_status', 'duration_ms', 'ok', 'error'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'http_status' => 'integer', 'duration_ms' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }
}
