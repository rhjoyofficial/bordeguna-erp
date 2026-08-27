<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Exceptions\ImmutablePunchException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A raw attendance punch. Write-once by design (ATT-03 — the SAP/Odoo pattern
 * Joy named explicitly): once captured, a punch is never edited or deleted by
 * any code path, including HR's. Corrections are separate records (Phase 2c)
 * and derived attendance is recalculated from these rows.
 *
 * That guarantee is enforced on the model rather than left to convention, so a
 * future careless `->update()` anywhere fails loudly instead of quietly
 * rewriting history that payroll depends on.
 */
class AttendancePunch extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'punched_at',
        'source',
        'direction',
        'device_identifier',
        'enroll_id',
        'latitude',
        'longitude',
        'gps_accuracy_meters',
        'distance_from_office_meters',
        'ip_address',
        'user_agent',
        'device_metadata',
        'is_outside_geofence',
        'needs_review',
        'review_reason',
        'dedup_key',
        'note',
        'import_batch_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'source' => PunchSource::class,
            'direction' => PunchDirection::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_meters' => 'decimal:2',
            'distance_from_office_meters' => 'decimal:2',
            'device_metadata' => 'array',
            'is_outside_geofence' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The ATT-03 guarantee. `updating` also covers save() on a loaded model,
        // and `deleting` covers both delete() and cascading model deletes.
        static::updating(function (): never {
            throw ImmutablePunchException::forUpdate();
        });

        static::deleting(function (): never {
            throw ImmutablePunchException::forDelete();
        });
    }

    /**
     * ATT-08's idempotency key: device + employee + timestamp. Web and manual
     * punches have no device, so their source stands in for one — two identical
     * manual entries for the same second are still the same punch.
     */
    public static function dedupKey(int $employeeId, Carbon|string $punchedAt, PunchSource $source, ?string $device = null): string
    {
        $timestamp = Carbon::parse($punchedAt)->format('Y-m-d H:i:s');

        return hash('sha256', implode('|', [$employeeId, $timestamp, $device ?: $source->value]));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Punches on one calendar date. Overnight-shift attribution (SCH-03) is a
     * processing concern and deliberately not applied here — this scope answers
     * "what was captured on this date", not "what does this date's shift own".
     */
    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('punched_at', Carbon::parse($date)->toDateString());
    }

    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('needs_review', true);
    }
}
