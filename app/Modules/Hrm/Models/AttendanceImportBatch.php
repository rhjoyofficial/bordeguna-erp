<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\PunchSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The outcome of one attendance file import (ATT-08). Holds the per-row report
 * so a skipped line is explainable - "37 duplicates" on its own tells HR
 * nothing about whether the export was double-run or the file was wrong.
 */
class AttendanceImportBatch extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * Outcome of the automatic post-import recompute, set by
     * ImportAttendancePunches on the instance it returns. Declared (typed)
     * properties, deliberately NOT Eloquent attributes: they describe what
     * this request just did, are never persisted, and a reloaded batch
     * honestly does not know them.
     */
    public ?int $recomputedDays = null;

    public ?string $processingError = null;

    protected $fillable = [
        'business_id',
        'filename',
        'source',
        'device_identifier',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'failed_count',
        'report',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => PunchSource::class,
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'failed_count' => 'integer',
            'report' => 'array',
        ];
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'import_batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A re-run of an already-imported export: everything recognised, nothing
     * new. Worth saying plainly so HR does not go hunting for a problem.
     */
    public function wasEntirelyDuplicate(): bool
    {
        return $this->total_rows > 0 && $this->imported_count === 0 && $this->duplicate_count === $this->total_rows;
    }
}
