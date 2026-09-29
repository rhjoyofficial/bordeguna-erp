<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to a cheque after its voucher (finance decision C5). No row
 * means the cheque is still pending.
 */
class ChequeStatus extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['pending' => 'Pending', 'cleared' => 'Cleared', 'bounced' => 'Bounced', 'cancelled' => 'Cancelled'];

    protected $fillable = ['business_id', 'voucher_id', 'status', 'status_on', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['status_on' => 'date:Y-m-d'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
