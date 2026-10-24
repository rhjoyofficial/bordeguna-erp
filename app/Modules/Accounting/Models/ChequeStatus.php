<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = ['business_id', 'voucher_id', 'status', 'status_on', 'note', 'updated_by', 'undone_at', 'undone_by', 'undo_reason'];

    protected function casts(): array
    {
        return ['status_on' => 'date:Y-m-d', 'undone_at' => 'datetime'];
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): an undone status reads as no status,
     * the cheque pending again. The row stays (who, when, why it was undone).
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('undone_at');
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
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
