<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
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
            'start_date' => 'date',
            'end_date' => 'date',
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
     */
    public static function forMonth(int $year, int $month): self
    {
        $spec = PayrollPeriodSpec::forMonth($year, $month);

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
