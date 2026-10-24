<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Collection;

/**
 * Reads balances off the ledger. There is no stored balance column anywhere —
 * a balance is always the sum of its entries, which is what keeps it
 * explainable and keeps a rerun of any posting job harmless.
 */
class LeaveBalance
{
    public function for(int $employeeId, int $leaveTypeId, int $year): float
    {
        return (float) LeaveLedgerEntry::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->forYear($year)
            ->sum('days');
    }

    /**
     * Every active type's balance for one employee, for a balances screen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summaryFor(int $employeeId, int $year): Collection
    {
        $totals = LeaveLedgerEntry::where('employee_id', $employeeId)
            ->forYear($year)
            ->selectRaw('leave_type_id, sum(days) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        $taken = LeaveLedgerEntry::where('employee_id', $employeeId)
            ->forYear($year)
            // A cancelled leave's give-back nets off what it took (wave 3).
            ->whereIn('entry_type', ['taken', 'cancelled'])
            ->selectRaw('leave_type_id, sum(days) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        return LeaveType::active()->orderBy('name')->get()->map(fn (LeaveType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'deducts_balance' => $type->deducts_balance,
            'annual_quota' => (float) $type->annual_quota,
            'balance' => round((float) ($totals[$type->id] ?? 0), 2),
            // Stored negative in the ledger; shown as a positive "used" figure.
            'taken' => round(abs((float) ($taken[$type->id] ?? 0)), 2),
            'can_carry_forward' => $type->can_carry_forward,
            'can_encash' => $type->can_encash,
            'carry_forward_cap' => $type->can_carry_forward ? $type->effectiveCarryForwardCap() : null,
        ]);
    }

    /**
     * The ledger behind one balance, newest first — the "explain this number"
     * view.
     *
     * @return Collection<int, LeaveLedgerEntry>
     */
    public function entriesFor(int $employeeId, int $year): Collection
    {
        return LeaveLedgerEntry::with('leaveType:id,name')
            ->where('employee_id', $employeeId)
            ->forYear($year)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }
}
