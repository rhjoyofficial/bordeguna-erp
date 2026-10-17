<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money that came in and was not a sale, with the proof behind it.
 *
 * ⚠ Received at the moment it is recorded. Income earned but not yet received
 * is a receivable, a different event with a different document.
 *
 * ⭐ Edit, delete and correct, Phase 0 (17 Oct 2026): a posted voucher is never
 * edited and never deleted. It is REVERSED (kept, marked, a mirror journal) or
 * CORRECTED (reversed, plus a new voucher labelled "Correction of ..."),
 * through the shared CorrectionService. Every list and sum asks `live()`.
 */
class IncomeVoucher extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity, Reversible;

    /**
     * The deposit slip, the bank advice, the scrap dealer's chit.
     *
     * ⚠⚠ Same reasoning as the expense voucher's receipts: a voucher without
     * its proof is a number somebody typed. Money arriving deserves the
     * evidence money leaving already had.
     */
    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = [
        'business_id',
        'voucher_number',
        'received_on',
        'money_account_id',
        'customer_id',
        'received_from',
        'total',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // ⚠ Widened 17 Oct 2026 (R11): the payer and the note were never
            // logged. History before that date says so; nothing is back-filled.
            ->logOnly(['voucher_number', 'received_on', 'money_account_id', 'customer_id', 'received_from', 'total', 'reference', 'note', 'reversed_at', 'reversal_reason'])
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IncomeVoucherLine::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The one journal it posted (filled at record time; backfilled conservatively for older vouchers). */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /** The correction link that reversed or corrected THIS voucher, if any. */
    public function correctionAsOriginal(): HasOne
    {
        return $this->hasOne(CorrectionLink::class, 'original_id')->where('original_type', $this->getMorphClass());
    }

    /** The link that made THIS voucher, when it is a correction of another one. */
    public function correctionAsSuccessor(): HasOne
    {
        return $this->hasOne(CorrectionLink::class, 'successor_id')->where('successor_type', $this->getMorphClass());
    }

    /** Posted, Reversed, or Corrected (reversed with a successor). */
    public function correctionStatus(): string
    {
        if (! $this->isReversed()) {
            return 'posted';
        }

        return $this->correctionAsOriginal?->successor_id !== null ? 'corrected' : 'reversed';
    }

    /**
     * Who the money came from, however the system knows them.
     */
    public function payer(): string
    {
        return $this->customer?->name ?? $this->received_from ?? 'Unnamed payer';
    }
}
