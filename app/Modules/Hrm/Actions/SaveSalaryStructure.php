<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets or revises an employee's salary (SAL-05/06, Correction C7c).
 *
 * A revision is close-old-open-new, the P1 pattern used throughout: the current
 * structure is closed the day before the new one starts and a fresh row opens.
 * Nothing is overwritten, so what someone was paid in a closed month stays
 * exactly as it was — the precondition for any reproducible historical payroll,
 * and for the arrear flow Phase 3d will build on top.
 *
 * Correction C7c decides ownership: the structure belongs to the business
 * holding the employee's active primary assignment. SAL-06's "one at a time" is
 * therefore enforced *globally*, not per business.
 */
class SaveSalaryStructure
{
    /**
     * @param  array<int, array{salary_component_id: int, value: float}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        string $effectiveFrom,
        array $lines,
        ?string $reason = null,
        bool $overtimeEligible = false,
    ): SalaryStructure {
        $from = Carbon::parse($effectiveFrom)->startOfDay();

        $businessId = $this->owningBusinessId($employee);
        $this->guardLines($lines);
        $this->guardLaterStructure($employee, $from);

        return DB::transaction(function () use ($employee, $from, $lines, $reason, $businessId, $overtimeEligible) {
            $current = SalaryStructure::withoutGlobalScope(BusinessScope::class)
                ->where('employee_id', $employee->id)
                ->activeOn($from)
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->first();

            if ($current !== null) {
                if (Carbon::parse($current->effective_from)->gte($from)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'A revision must start after the current structure began ('
                            .Carbon::parse($current->effective_from)->toDateString().').',
                    ]);
                }

                // Closed the day before, so the two never overlap — the
                // resolver would otherwise see two active structures.
                $current->update(['effective_to' => $from->copy()->subDay()]);
            }

            $structure = SalaryStructure::withoutGlobalScope(BusinessScope::class)->create([
                'business_id' => $businessId,
                'employee_id' => $employee->id,
                'effective_from' => $from,
                'effective_to' => null,
                // ADJ-01 through Correction C8d — eligibility rides on the
                // structure so it is effective-dated like every other salary
                // fact, and a historical recalculation uses the eligibility
                // that was actually in force rather than today's.
                'overtime_eligible' => $overtimeEligible,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $structure->lines()->create([
                    'salary_component_id' => $line['salary_component_id'],
                    'value' => $line['value'],
                ]);
            }

            return $structure->fresh(['lines.component']);
        });
    }

    /**
     * Correction C7c — the paying business is the one holding the active
     * primary assignment. Without one there is nobody to pay them.
     *
     * @throws ValidationException
     */
    private function owningBusinessId(Employee $employee): int
    {
        $assignment = $employee->currentAssignment();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no active primary assignment, so no business owns their salary yet.',
            ]);
        }

        return $assignment->business_id;
    }

    /**
     * Exactly one Basic, no duplicates, and no earning priced as a share of
     * Gross — Gross is the sum of the earnings, so that would be circular.
     *
     * @param  array<int, array{salary_component_id: int, value: float}>  $lines
     *
     * @throws ValidationException
     */
    private function guardLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A salary structure needs at least a Basic component.']);
        }

        $ids = array_column($lines, 'salary_component_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'Each component may appear only once in a structure.']);
        }

        $components = SalaryComponent::whereIn('id', $ids)->get();

        $basicCount = $components->where('is_basic', true)->count();

        if ($basicCount !== 1) {
            throw ValidationException::withMessages([
                'lines' => $basicCount === 0
                    ? 'A salary structure must include the Basic component.'
                    : 'A salary structure may include only one Basic component.',
            ]);
        }

        foreach ($components as $component) {
            if ($component->isEarning() && ! $component->calculation_basis->allowedForEarning()) {
                throw ValidationException::withMessages([
                    'lines' => "{$component->name} is an earning, so it cannot be a percentage of Gross — Gross is the sum of the earnings.",
                ]);
            }
        }
    }

    /**
     * Refuse to slot a revision in behind one that already starts later, rather
     * than silently stranding it.
     *
     * @throws ValidationException
     */
    private function guardLaterStructure(Employee $employee, Carbon $from): void
    {
        $later = SalaryStructure::withoutGlobalScope(BusinessScope::class)
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '>', $from->toDateString())
            ->orderBy('effective_from')
            ->first();

        if ($later !== null) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has a salary structure starting '
                    .Carbon::parse($later->effective_from)->toDateString().'. Remove it first, or date this one after it.',
            ]);
        }
    }
}
