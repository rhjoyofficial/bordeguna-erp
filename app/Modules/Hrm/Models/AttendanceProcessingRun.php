<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One business's slice of one nightly attendance-processing run (C15d).
 *
 * The row is opened *before* the work starts and closed after, so a run that
 * dies leaves a `running` row behind rather than no evidence at all. That is
 * the case worth being able to see: a job that never finished looks identical
 * to a job that never started if the only record is written on success.
 */
class AttendanceProcessingRun extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'business_id',
        'from_date',
        'to_date',
        'status',
        'days_processed',
        'days_finalized',
        'days_skipped_locked',
        'days_skipped_finalized',
        'employees_seen',
        'started_at',
        'finished_at',
        'duration_ms',
        'failure_class',
        'failure_message',
        'trigger',
        'is_dry_run',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
            'days_processed' => 'integer',
            'days_finalized' => 'integer',
            'days_skipped_locked' => 'integer',
            'days_skipped_finalized' => 'integer',
            'employees_seen' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'is_dry_run' => 'boolean',
        ];
    }

    public static function start(Carbon $from, Carbon $to, string $trigger, bool $isDryRun): self
    {
        return static::create([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'trigger' => $trigger,
            'is_dry_run' => $isDryRun,
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    public function complete(array $counts): self
    {
        $this->update([...$counts, ...$this->closing(self::STATUS_COMPLETED)]);

        return $this;
    }

    /**
     * @param  array<string, int>  $counts
     */
    public function fail(Throwable $e, array $counts = []): self
    {
        $this->update([
            ...$counts,
            ...$this->closing(self::STATUS_FAILED),
            'failure_class' => $e::class,
            // Truncated deliberately: a stack trace belongs in the log, and a
            // multi-megabyte message would make this table unreadable in the
            // one situation where somebody is reading it in a hurry.
            'failure_message' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function closing(string $status): array
    {
        $finishedAt = now();

        return [
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $this->started_at->diffInMilliseconds($finishedAt)),
        ];
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }
}
