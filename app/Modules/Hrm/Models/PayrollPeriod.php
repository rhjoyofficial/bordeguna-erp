<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One payroll month (Correction C7b: the 24th through the 23rd).
 *
 * The period is the stable container; each calculation attempt is a versioned
 * run, because WFL-06 requires a reopened payroll to produce a new version
 * rather than overwrite the previous figures.
 */
class PayrollPeriod extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'year',
        'month',
        'start_date',
        'end_date',
        'divisor_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'divisor_days' => 'integer',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class)->orderByDesc('version');
    }

    public function latestRun(): ?PayrollRun
    {
        return $this->runs()->first();
    }

    /**
     * The period's dates as the engine's pure value object.
     */
    public function toSpec(): PayrollPeriodSpec
    {
        return new PayrollPeriodSpec(
            $this->year,
            $this->month,
            Carbon::parse($this->start_date)->startOfDay(),
            Carbon::parse($this->end_date)->startOfDay(),
            $this->divisor_days,
        );
    }

    /**
     * Find or open the period for a month, with its C7b bounds.
     *
     * ⚠ The cutoff day comes from the business's own setting (Settings →
     * Business, PayrollCycle::startDay), not the constant it used to be.
     * `firstOrCreate` means an EXISTING period keeps the dates it was opened
     * with even if the setting changes later - which is correct, because a
     * payroll already calculated on 24-to-23 must not silently claim it
     * covered different days.
     */
    public static function forMonth(int $year, int $month): self
    {
        $spec = PayrollPeriodSpec::forMonth($year, $month, PayrollCycle::startDay());

        return static::firstOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'start_date' => $spec->start,
                'end_date' => $spec->end,
                'divisor_days' => $spec->divisorDays,
            ],
        );
    }

    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }
}
