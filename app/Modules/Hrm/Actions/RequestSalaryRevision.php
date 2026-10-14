<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Proposes an increment (ADJ-06).
 *
 * ADJ-06 ticked "Proposal → Approval → Effective salary revision" and left "HR
 * direct entry" blank, so the figures are held as a proposal and no salary
 * structure exists until someone approves it. Writing the structure first and
 * withdrawing it on rejection was never an option: a structure that exists is a
 * structure the next payroll run would pay.
 *
 * The proposal is validated now rather than at approval, because an approver
 * should be deciding whether a raise is warranted, not discovering that it was
 * malformed all along.
 */
class RequestSalaryRevision
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @param  array<int, array{salary_component_id: int, value: float}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        string $effectiveFrom,
        array $lines,
        string $reason,
        User $requestedBy,
        bool $overtimeEligible = false,
        ?string $requestedFrom = null,
    ): SalaryRevision {
        $from = Carbon::parse($effectiveFrom)->startOfDay();
        $requested = $this->requestedDate($from, $requestedFrom);

        $this->guardOwnership($employee);
        $this->guardLines($lines);
        $this->guardCycleStart($from);
        $this->guardEffectiveDate($employee, $from);
        $this->guardPending($employee);

        return DB::transaction(function () use ($employee, $from, $requested, $lines, $reason, $requestedBy, $overtimeEligible) {
            $revision = SalaryRevision::create([
                'employee_id' => $employee->id,
                'effective_from' => $from,
                'requested_effective_from' => $requested,
                'proposed_lines' => array_map(fn (array $line) => [
                    'salary_component_id' => (int) $line['salary_component_id'],
                    'value' => (float) $line['value'],
                ], array_values($lines)),
                'overtime_eligible' => $overtimeEligible,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $revision->submitForApproval($requestedBy);
            $this->notify->handle($approval, $revision->summaryLine());

            return $revision->fresh();
        });
    }

    /**
     * Correction C7c — a revision belongs to the business paying the employee.
     *
     * @throws ValidationException
     */
    private function guardOwnership(Employee $employee): void
    {
        if ($employee->currentAssignment() === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no active primary assignment, so no business owns their salary.',
            ]);
        }
    }

    /**
     * The same structural rules `SaveSalaryStructure` enforces, applied at
     * proposal time so an approver never inherits a broken structure.
     *
     * @param  array<int, array{salary_component_id: int, value: float}>  $lines
     *
     * @throws ValidationException
     */
    private function guardLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A revision needs at least a Basic component.']);
        }

        $ids = array_column($lines, 'salary_component_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'Each component may appear only once.']);
        }

        $components = SalaryComponent::whereIn('id', $ids)->get();

        if ($components->where('is_basic', true)->count() !== 1) {
            throw ValidationException::withMessages(['lines' => 'A revision must include exactly one Basic component.']);
        }

        foreach ($components as $component) {
            if ($component->isEarning() && ! $component->calculation_basis->allowedForEarning()) {
                throw ValidationException::withMessages([
                    'lines' => "{$component->name} is an earning, so it cannot be a percentage of Gross.",
                ]);
            }
        }
    }

    /**
     * Rule #16 (approved 14 Oct 2026): a revision takes effect only from a
     * payroll cycle start, the 24th (the business's configured start day; the
     * 1st when it runs on calendar months). A mid-cycle date priced the whole
     * month at the new salary, so it is refused here, never rounded: the
     * message names the next cycle start and `suggested_effective_from`
     * carries it back so the form can offer it for the proposer to confirm.
     *
     * @throws ValidationException
     */
    private function guardCycleStart(Carbon $from): void
    {
        $cycle = PayrollCycle::containing($from);

        if ($cycle['start']->equalTo($from)) {
            return;
        }

        $next = $cycle['end']->copy()->addDay()->toDateString();

        throw ValidationException::withMessages([
            'effective_from' => "A salary change can start only on a payroll cycle start. {$from->toDateString()} is mid-cycle; the next cycle starts {$next}. Use {$next} if that is what you mean.",
            'suggested_effective_from' => $next,
        ]);
    }

    /**
     * The mid-cycle date the proposer first typed, kept beside the effective
     * one when they accepted the offered cycle start (rule #16). It must be a
     * date the offered start actually follows, so the record cannot claim a
     * request that was never made. Null when the first date was already a
     * cycle start.
     *
     * @throws ValidationException
     */
    private function requestedDate(Carbon $from, ?string $requestedFrom): ?Carbon
    {
        if ($requestedFrom === null || $requestedFrom === '') {
            return null;
        }

        $requested = Carbon::parse($requestedFrom)->startOfDay();

        if ($requested->equalTo($from)) {
            return null;
        }

        $offered = PayrollCycle::containing($requested)['end']->copy()->addDay();

        if (! $offered->equalTo($from)) {
            throw ValidationException::withMessages([
                'effective_from' => "The next cycle start after {$requested->toDateString()} is {$offered->toDateString()}, not {$from->toDateString()}.",
            ]);
        }

        return $requested;
    }

    /**
     * SAL-05 permits a retroactive date, so reaching backwards is allowed — but
     * not behind the structure being revised, which would leave the two
     * overlapping and the resolver seeing both.
     *
     * @throws ValidationException
     */
    private function guardEffectiveDate(Employee $employee, Carbon $from): void
    {
        $current = SalaryStructure::withoutGlobalScope(BusinessScope::class)
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        if ($current === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no salary structure yet. Set an opening structure before revising it.',
            ]);
        }

        $latestStart = Carbon::parse($current->effective_from);

        if ($latestStart->gte($from)) {
            // SAL-05 lets a revision be dated ahead, so the row this must follow
            // is not always one already in force. Saying which it is saves the
            // reader from checking the history to understand the refusal.
            throw ValidationException::withMessages([
                'effective_from' => $latestStart->gt(Carbon::today())
                    ? 'This employee already has a structure scheduled to start '.$latestStart->toDateString()
                        .'. Date this revision after that, or remove the scheduled one first.'
                    : 'A revision must start after the current structure began ('.$latestStart->toDateString().').',
            ]);
        }
    }

    /**
     * One open proposal at a time. Two pending revisions for the same person
     * would each be valid alone and contradictory together, and whichever was
     * approved second would fail confusingly at the P1 close-old-open-new step.
     *
     * @throws ValidationException
     */
    private function guardPending(Employee $employee): void
    {
        $pending = SalaryRevision::where('employee_id', $employee->id)
            ->whereNull('applied_at')
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Pending))
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee already has a salary revision awaiting approval.',
            ]);
        }
    }
}
