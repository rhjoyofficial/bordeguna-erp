<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Hrm\Corrections\PayrollInputCancelPolicy;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): an approved overtime, bonus
 * award or salary revision cancelled while its payroll month still takes
 * inputs (PayrollInputCancelPolicy says when).
 *
 *   overtime          marked; payroll's `payable` skips it. Holiday work also
 *                     takes back its compensatory leave with one keyed
 *                     adjustment on the leave ledger (never a deleted row)
 *   bonus award       marked; payroll's `approved` skips it
 *   salary revision   marked; the salary row it opened is removed (no payroll
 *                     that is closed for inputs used it, the policy checked)
 *                     and the salary before it is open again, as it was
 *
 * The row keeps its approval; `live` is cleared so the same day or the same
 * bonus can be entered again, right.
 *
 * One transaction, the row re-read under a lock and the policy asked again: a
 * double click finds it already cancelled.
 */
class CancelPayrollInput
{
    public function __construct(private readonly PayrollInputCancelPolicy $policy) {}

    public function handle(Model $input, User $by, ActionReason $why): Model
    {
        return DB::transaction(function () use ($input, $by, $why) {
            $input = $input::query()->withoutGlobalScopes()->whereKey($input->getKey())->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($input, $by);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $month = $this->policy->periodOf($input)?->label();

            match (true) {
                $input instanceof OvertimeAssignment => $this->overtime($input, $by, $why),
                $input instanceof SalaryRevision => $this->revision($input),
                default => null,
            };

            $input->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $why->display(),
                ...($input instanceof SalaryRevision ? [] : ['live' => null]),
            ])->save();

            CorrectionLog::event('cancelled', $by, $input, array_filter([
                'reason' => $why->display(),
                'month' => $month,
                'what' => $this->policy->label($input),
            ]));

            return $input->fresh();
        });
    }

    private function overtime(OvertimeAssignment $overtime, User $by, ActionReason $why): void
    {
        $days = (float) $overtime->compensatory_days;

        if (! $overtime->is_holiday_work || $days <= 0) {
            return;
        }

        $type = LeaveType::query()->where('code', LeaveType::COMPENSATORY)->first();

        if ($type === null) {
            return;
        }

        LeaveLedgerEntry::once("overtime_assignment_cancel:{$overtime->id}", [
            'business_id' => $overtime->business_id,
            'employee_id' => $overtime->employee_id,
            'leave_type_id' => $type->id,
            'year' => (int) Carbon::parse($overtime->work_date)->year,
            'entry_type' => LeaveLedgerEntryType::Adjustment,
            'days' => -$days,
            'occurred_on' => Carbon::parse($overtime->work_date)->toDateString(),
            'note' => mb_substr("Overtime #{$overtime->id} cancelled: {$why->display()}", 0, 250),
            'reference_type' => $overtime->getMorphClass(),
            'reference_id' => $overtime->id,
            'created_by' => $by->id,
        ]) ?? throw ValidationException::withMessages(['reason_text' => "Overtime #{$overtime->id} already gave its leave back."]);
    }

    /**
     * Close-old-open-new, undone: the row this revision opened goes, and the
     * one it closed the day before is open again.
     */
    private function revision(SalaryRevision $revision): void
    {
        $structure = SalaryStructure::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($revision->salary_structure_id);
        $dayBefore = Carbon::parse($structure->effective_from)->subDay()->toDateString();

        $previous = SalaryStructure::query()->withoutGlobalScopes()
            ->where('employee_id', $structure->employee_id)
            ->whereKeyNot($structure->id)
            ->whereDate('effective_to', $dayBefore)
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();

        $structure->lines()->delete();
        $structure->delete();
        $previous?->update(['effective_to' => null]);
    }
}
