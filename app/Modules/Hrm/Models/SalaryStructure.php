<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\EffectiveDated;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use App\Modules\Hrm\Salary\SalaryResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One employee's salary for a period of time (SAL-05/06, Correction C7c).
 *
 * Effective-dated with the same P1 concern used by schedules, employee
 * assignments and attendance policies. A revision closes this row and opens a
 * new one, so an increment never rewrites what someone was actually paid in a
 * month that has already closed — which is what makes a reproducible historical
 * payroll possible at all.
 */
class SalaryStructure extends Model
{
    use BelongsToBusiness, EffectiveDated, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'effective_from',
        'effective_to',
        'overtime_eligible',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'overtime_eligible' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'effective_from', 'effective_to', 'overtime_eligible', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The contractual figures, resolved to money. Not a payslip — it knows
     * nothing about attendance, overtime or loans.
     */
    public function breakdown(): SalaryBreakdown
    {
        return app(SalaryResolver::class)->resolve(
            $this->relationLoaded('lines') ? $this->lines : $this->lines()->with('component')->get()
        );
    }

    /**
     * The structure governing an employee on a date.
     *
     * Queried without the business scope on purpose: SAL-06 allows only one
     * active structure per employee *globally*, and Correction C7c says it
     * belongs to whichever business holds their primary assignment. Scoping
     * this to the current business would hide a structure that legitimately
     * exists, and could let a second one be created.
     */
    public static function activeFor(int $employeeId, Carbon|string|null $on = null): ?self
    {
        return static::withoutGlobalScope(BusinessScope::class)
            ->where('employee_id', $employeeId)
            ->activeOn($on ?? now())
            ->with('lines.component')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every structure an employee has ever had, newest first — the salary
     * history SAL-05 implies once retroactive revisions are allowed.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function historyFor(int $employeeId)
    {
        return static::withoutGlobalScope(BusinessScope::class)
            ->where('employee_id', $employeeId)
            ->with('lines.component')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
