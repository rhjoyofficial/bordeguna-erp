<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Enums\RecurringOccurrenceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One period of a standing cost: September's Nikola Host, still due.
 */
class RecurringExpenseOccurrence extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'recurring_expense_id', 'due_on', 'period_label', 'expected_amount',
        'status', 'expense_voucher_id', 'skipped_reason', 'settled_by', 'settled_at', 'source_key',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'expected_amount' => 'decimal:2',
            'status' => RecurringOccurrenceStatus::class,
            'settled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'expense_voucher_id', 'skipped_reason'])
            ->logOnlyDirty();
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', RecurringOccurrenceStatus::Due);
    }

    /**
     * Create at most once, keyed on the period.
     *
     * ⚠⚠ The unique index on (business_id, source_key) is the real guarantee;
     * this keeps a re-run quiet rather than throwing - the same shape
     * `LeaveLedgerEntry::once` and `PayrollAdjustment::once` use. It is what
     * makes the nightly roll safe to run twice, and safe after a restore.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function once(string $sourceKey, array $attributes): ?self
    {
        if (static::withoutGlobalScopes()->where('source_key', $sourceKey)->exists()) {
            return null;
        }

        return static::create([...$attributes, 'source_key' => $sourceKey]);
    }

    /**
     * What the voucher actually cost against what was expected.
     *
     * ⚠ Null until it is recorded. Zero would read as "no difference", which is
     * a different and much more comforting claim than "not yet known".
     */
    public function variance(): ?float
    {
        $actual = $this->voucher?->total;

        return $actual === null ? null : round((float) $actual - (float) $this->expected_amount, 2);
    }
}
