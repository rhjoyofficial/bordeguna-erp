<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's earned-leave decision for one month: granted, or withheld
 * with the counts it was refused on.
 *
 * ⭐ 27 Oct 2026 (Joy): "reset full recount from aug 24 to sep 23, sep 24 to
 * oct 23". A row says which month it counted (`basis`): the CALENDAR month the
 * first two live months were decided on, or the 24-23 payroll CYCLE every
 * month is decided on now. The recount marks a calendar row superseded rather
 * than deleting it, so the old decision and why it stopped counting stay on
 * record next to the cycle decision that replaced it.
 */
class EarnedLeaveAccrual extends Model
{
    use BelongsToBusiness, HasFactory;

    public const BASIS_CALENDAR = 'calendar';

    public const BASIS_CYCLE = 'cycle';

    protected $fillable = [
        'business_id',
        'employee_id',
        'year',
        'month',
        'days_granted',
        'withheld_reason',
        'ordinary_lates',
        'serious_lates',
        'basis',
        'leave_ledger_entry_id',
        'superseded_at',
        'superseded_reason',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'days_granted' => 'decimal:2',
            'ordinary_lates' => 'integer',
            'serious_lates' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The grant entry this decision put on the ledger and that still stands.
     * Null while the month is withheld, and after the grant was reversed.
     */
    public function grantEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class, 'leave_ledger_entry_id');
    }

    public function wasWithheld(): bool
    {
        return $this->withheld_reason !== null;
    }

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * The decisions that stand: a superseded calendar row is history only.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }
}
