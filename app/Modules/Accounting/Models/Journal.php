<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Exceptions\ImmutableJournalException;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the General Ledger: dated, balanced, immutable, and pointing back
 * at whatever caused it.
 *
 * ⚠⚠ NOTHING CREATES ONE OF THESE EXCEPT Actions\PostJournal, and a structural
 * test enforces it. Joy's standing rule for Inventory - "everything that changes
 * value goes through the same pipeline; never multiple code paths that can
 * modify it independently" - applies with more force here, because this ledger
 * is the one every other one will eventually report into.
 */
class Journal extends Model
{
    use BelongsToBusiness;

    /**
     * ⚠ There is no `updated_at`. A row that is never updated should not carry a
     * column claiming otherwise.
     */
    public const UPDATED_AT = null;

    /**
     * ⚠⚠ EMPTY ON PURPOSE. Not a single attribute is mass assignable, because
     * PostJournal fills them explicitly and nothing else may create a journal at
     * all. An empty $fillable makes "who is allowed to build this" a question
     * with one answer.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'journal_type' => JournalType::class,
            'journal_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
        ];
    }

    public function scopeOfType(Builder $query, JournalType $type): Builder
    {
        return $query->where('journal_type', $type);
    }

    protected static function booted(): void
    {
        // ⚠ `updating` also covers save() on a loaded model; `deleting` covers
        // both delete() and a cascading model delete. Same guarantee as
        // AttendancePunch, which has held since ATT-03.
        static::updating(function (): never {
            throw ImmutableJournalException::forUpdate();
        });

        static::deleting(function (): never {
            throw ImmutableJournalException::forDelete();
        });
    }

    /**
     * ⚠⚠ THE EVENTS ALONE WERE NOT ENOUGH, and a test found it.
     *
     * `$fillable` is empty, so `update(['memo' => 'x'])` fills nothing, leaves
     * the model clean, and `save()` returns early WITHOUT firing `updating`.
     * The write silently did nothing and reported success - which is worse than
     * either changing the row or refusing, because the caller believes it
     * worked. Refusing out loud is the only honest answer.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableJournalException::forUpdate();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The document, payslip or movement that caused this journal. NULL for a
     * manual journal, which is the only kind Phase 2 produces.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The journal this one undoes, if it is a reversal.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /**
     * The journal that undoes this one, if somebody has reversed it.
     */
    public function reversal(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_journal_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_journal_id !== null;
    }

    public function hasBeenReversed(): bool
    {
        return $this->reversal()->exists();
    }

    /**
     * ⚠ Sums the STORED line amounts rather than recomputing anything. Should
     * always be zero; the integrity command in slice 2.4 says so out loud.
     */
    public function balance(): Money
    {
        return Money::ofMinor((int) $this->lines()->sum('amount_minor'));
    }

    public function totalDebits(): Money
    {
        return Money::ofMinor((int) $this->lines()->where('amount_minor', '>', 0)->sum('amount_minor'));
    }

    public function scopeUpTo(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('journal_date', '<=', $date);
    }
}
