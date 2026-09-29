<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A courier's payout statement: which parcels it paid out, what it charged, and
 * the money that arrived (courier slice C2).
 *
 * ⚠ A DRAFT until posted, and immutable after. The draft is where the lines the
 * ERP cannot place are decided by a person; posting is where the books move.
 */
class CourierStatement extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $fillable = [
        'business_id',
        'money_account_id',
        'statement_reference',
        'statement_date',
        'status',
        'source_filename',
        'source_sha256',
        'received_money_account_id',
        'deposit_amount',
        'received_on',
        'share_amount',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date:Y-m-d',
            'received_on' => 'date:Y-m-d',
            'deposit_amount' => 'decimal:4',
            'share_amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statement_reference', 'statement_date', 'status', 'deposit_amount', 'share_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function receivedInto(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'received_money_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CourierStatementLine::class)->orderBy('row_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * What the whole file pays out, every brand's parcels included. The deposit
     * the bank saw must equal it.
     */
    public function fileTotal(): Money
    {
        return Money::sum($this->lines->map(fn (CourierStatementLine $line) => Money::ofTaka((string) $line->payout)));
    }
}
