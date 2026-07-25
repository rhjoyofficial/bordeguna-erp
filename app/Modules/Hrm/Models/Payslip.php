<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's payslip within a run.
 *
 * `calculation` stores the engine's own derivation rather than something to be
 * re-derived on demand: after a policy or salary revision, re-running the maths
 * would produce today's answer, not the one that was actually paid. PAY-12 and
 * PAY-13 both depend on that distinction.
 */
class Payslip extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'payroll_run_id',
        'employee_id',
        'gross_salary',
        'total_earnings',
        'total_deductions',
        'net',
        'is_blocked',
        'block_reasons',
        'calculation',
        'salary_structure_id',
    ];

    protected function casts(): array
    {
        return [
            'gross_salary' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'is_blocked' => 'boolean',
            'block_reasons' => 'array',
            'calculation' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sequence')->orderBy('id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    /**
     * The engine's plain-language account of how this payslip was reached.
     *
     * @return array<int, string>
     */
    public function explanation(): array
    {
        return $this->calculation['notes'] ?? [];
    }
}
