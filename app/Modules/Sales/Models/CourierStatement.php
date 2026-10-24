<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
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
 *
 * ⭐ B13 (24 Oct 2026, gap S4): a posted statement that was wrong is REVERSED
 * through the corrections engine (status `reversed`, the reversal columns,
 * `live_marker` cleared so the right file can be imported again). Never edited.
 */
class CourierStatement extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    /** The bank's or wallet's proof that the payout arrived. */
    public const PAYOUT_PROOF = 'payout_proof';

    /** Where the courier's original files are kept, on the private `local` disk. */
    public const SOURCE_DISK = 'local';

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    protected $fillable = [
        'business_id',
        'money_account_id',
        'provider_code',
        'format',
        'statement_reference',
        'statement_date',
        'status',
        'source_filename',
        'source_sha256',
        'source_path',
        'row_errors',
        'received_money_account_id',
        'deposit_amount',
        'received_on',
        'bank_reference',
        'deposit_difference_reason',
        'deposit_difference_booked',
        'payable_settled_amount',
        'share_amount',
        'created_by',
        'posted_by',
        'posted_at',
        'posted_on',
        'cutoff_note',
        'live_marker',
        'reversed_on',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'reversal_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date:Y-m-d',
            'received_on' => 'date:Y-m-d',
            'posted_on' => 'date:Y-m-d',
            'deposit_amount' => 'decimal:4',
            'share_amount' => 'decimal:4',
            'payable_settled_amount' => 'decimal:4',
            'deposit_difference_booked' => 'decimal:4',
            'row_errors' => 'array',
            'posted_at' => 'datetime',
            'reversed_on' => 'date:Y-m-d',
            'reversed_at' => 'datetime',
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

    public function canAccessAttachments(User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    /**
     * @return list<array{row: int, consignment: ?string, message: string}>
     */
    public function rowErrors(): array
    {
        return array_values($this->row_errors ?? []);
    }

    /** ⭐ B13: reversed through the corrections engine (not the Reversible trait: `status` is a column here). */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
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
