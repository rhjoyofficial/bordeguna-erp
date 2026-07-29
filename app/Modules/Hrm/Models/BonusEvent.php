<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An occasion that pays a bonus (ADJ-05).
 *
 * ADJ-05 ticked exactly one option — "bonus event অনুযায়ী configurable" — and
 * left the other four blank, including the length-of-service and performance
 * rules. So a bonus here is an *event* carrying its own rule, not a standing
 * entitlement: Eid, a festival, a one-off. Nothing about service length or
 * performance is modelled, because nothing about them was answered.
 *
 * The basis reuses SAL-04's `SalaryCalculationBasis` rather than inventing a
 * parallel enum: "a fixed amount, or a share of Basic or Gross" is the same
 * question, already settled.
 */
class BonusEvent extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'name',
        'calculation_basis',
        'value',
        'payroll_period_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calculation_basis' => SalaryCalculationBasis::class,
            'value' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'calculation_basis', 'value', 'payroll_period_id', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(BonusAward::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What this event is worth to one employee, given their salary at the time.
     *
     * Resolved once, at award time, and then stored on the award: a later
     * increment must not retroactively change what a festival bonus was worth.
     */
    public function amountFor(SalaryBreakdown $salary): float
    {
        $value = (float) $this->value;

        return round(match ($this->calculation_basis) {
            SalaryCalculationBasis::Fixed => $value,
            SalaryCalculationBasis::PercentOfBasic => $salary->basic * $value / 100,
            SalaryCalculationBasis::PercentOfGross => $salary->gross * $value / 100,
            SalaryCalculationBasis::PerPresentDay => $this->refuseAttendancePricedBasis(),
        }, 2);
    }

    public function basisNoteFor(SalaryBreakdown $salary): string
    {
        return match ($this->calculation_basis) {
            SalaryCalculationBasis::Fixed => number_format((float) $this->value, 2),
            SalaryCalculationBasis::PercentOfBasic => sprintf(
                '%s%% of Basic %s',
                rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.'),
                number_format($salary->basic, 2),
            ),
            SalaryCalculationBasis::PercentOfGross => sprintf(
                '%s%% of Gross %s',
                rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.'),
                number_format($salary->gross, 2),
            ),
            SalaryCalculationBasis::PerPresentDay => $this->refuseAttendancePricedBasis(),
        };
    }

    /**
     * Correction C14 added a fourth `SalaryCalculationBasis`, and this enum is
     * shared with the bonus screen rather than duplicated. A bonus resolved
     * "per day reported for work" has no meaning: an award is priced once,
     * against the salary in force at award time, with no payroll period and no
     * attendance in scope.
     *
     * ⚠ **This throws rather than returning 0.00 deliberately.** Validation
     * already blocks the basis at creation, so this is unreachable through the
     * UI and only a hand-edited row can reach it. A zero would pay nobody and
     * look like a correctly-calculated bonus of nothing, which is precisely the
     * class of defect this project keeps finding late; failing loudly stops the
     * run instead.
     */
    private function refuseAttendancePricedBasis(): never
    {
        throw new LogicException(sprintf(
            'Bonus event %d uses the "%s" basis, which is priced by attendance and is not valid for a bonus award.',
            $this->id,
            $this->calculation_basis->value,
        ));
    }
}
