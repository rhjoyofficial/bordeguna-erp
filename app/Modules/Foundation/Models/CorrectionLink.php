<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Reason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One step of a correction chain: original -> reversal -> successor
 * (PHASE-0-CONTRACT v2, 1.7).
 *
 * ⚠⚠ APPEND-ONLY, like a journal. Updating or deleting refuses out loud. A link
 * is the evidence the Corrections report and the History tab read; it is never
 * Recyclable and never purged.
 *
 * ⚠ `live_key` = "{type}:{id}" of the document being reversed or corrected, and
 * it is UNIQUE: the database refuses a second reversal of the same document even
 * when every application check was skipped. A successor is a different document,
 * so it can be corrected later under its own key.
 */
class CorrectionLink extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'original_date' => 'date:Y-m-d',
            'successor_date' => 'date:Y-m-d',
            'date_used' => 'date:Y-m-d',
            'closed_period_exception' => 'boolean',
            'amount_basis_minor' => 'integer',
            'original_minor' => 'integer',
            'successor_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A correction link is never changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('A correction link is never deleted.');
        });
    }

    public static function liveKeyFor(Model $document): string
    {
        return $document->getMorphClass().':'.$document->getKey();
    }

    public function original(): MorphTo
    {
        return $this->morphTo('original');
    }

    public function successor(): MorphTo
    {
        return $this->morphTo('successor');
    }

    public function originalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'original_journal_id');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function successorJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'successor_journal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function periodException(): BelongsTo
    {
        return $this->belongsTo(ClosedPeriodException::class, 'period_exception_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CorrectionRequest::class, 'correction_request_id');
    }

    public function isCorrection(): bool
    {
        return $this->successor_id !== null;
    }

    /** "Wrong amount: typed 5,000 for 4,500" or the text alone. */
    public function reasonDisplay(): string
    {
        $name = $this->reason?->name;

        return $name === null ? (string) $this->reason_text : "{$name}: {$this->reason_text}";
    }
}
