<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\SampleResult;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A sample a supplier sent, and the Owner's verdict on it (Joy, answer 15; build
 * plan D13).
 *
 * ⚠⚠ APPEND-ONLY, ENFORCED HERE. What arrived (date, item, note) never changes
 * once recorded. The verdict is written ONCE, by the Owner; after that the row
 * is closed. A re-test is a new sample, so the history shows every verdict ever
 * given instead of the latest opinion. Enforced on the model rather than on one
 * route, so an importer or a tinker session meets the same rule.
 *
 * ⚠⚠ Visible only through its lead, exactly like DistributorInspection.
 */
class SupplierLeadSample extends Model
{
    use BelongsToBusiness;

    /** What arrived. Fixed at creation. */
    private const RECEIPT_COLUMNS = ['business_id', 'lead_id', 'received_on', 'item', 'note', 'recorded_by'];

    /** The verdict. Written once, from empty. */
    private const VERDICT_COLUMNS = ['result', 'result_note', 'judged_by', 'judged_at'];

    protected $fillable = ['business_id', 'lead_id', 'received_on', 'item', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'result' => SampleResult::class,
            'judged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible_lead', fn (Builder $query) => $query->whereHas('lead'));

        static::updating(function (self $sample): void {
            $dirty = array_keys($sample->getDirty());

            $rewritten = array_values(array_intersect($dirty, self::RECEIPT_COLUMNS));
            if ($rewritten !== []) {
                throw new RuntimeException(
                    'What a supplier sent cannot be changed ('.implode(', ', $rewritten).'). Record another sample.'
                );
            }

            if (array_intersect($dirty, self::VERDICT_COLUMNS) !== [] && $sample->getOriginal('result') !== null) {
                throw new RuntimeException('This sample has already been judged. A re-test is a new sample.');
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('A sample is part of the supplier\'s history and cannot be deleted.');
        });
    }

    public function isJudged(): bool
    {
        return $this->result !== null;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function judgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judged_by');
    }
}
