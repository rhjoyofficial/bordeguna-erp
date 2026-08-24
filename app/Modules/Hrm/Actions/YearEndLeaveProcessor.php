<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Year-end carry-forward and lapse (Corrections C6b and C6d).
 *
 * The whole design rests on one rule: **the preview and the commit run the same
 * computation.** `plan()` produces the projection and is the only place any
 * decision is made; `commit()` calls `plan()` and writes exactly what it
 * returned. There is no second code path that could drift from what HR
 * reviewed, and C6d's advance warning is simply `plan()` run early.
 *
 * Rerunning is safe. Every posting goes through the ledger's `source_key`,
 * which is deterministic per employee/type/year/entry — so a second run finds
 * its entries already present and posts nothing. That matters because this job
 * runs on a schedule, and a scheduler that fires twice must not double-charge
 * anyone's leave.
 *
 * Only types that may carry forward are touched (C6c), and encashment is not
 * dealt with here at all: C6a settles that on separation, not annually.
 */
class YearEndLeaveProcessor
{
    public function __construct(private readonly LeaveBalance $balances) {}

    /**
     * What would happen at the close of `$year` — the single source of truth for
     * both the preview and the commit.
     *
     * @return array{
     *     year: int,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{employees: int, carried: float, lapsed: float}
     * }
     */
    public function plan(int $year): array
    {
        $types = LeaveType::active()->where('can_carry_forward', true)->orderBy('name')->get();

        $employees = Employee::whereHas('assignments', fn ($query) => $query
            ->activeOn(Carbon::create($year, 12, 31))
            ->where('is_primary', true))
            ->orderBy('employee_code')->get();

        $rows = [];
        $employeesAffected = [];
        $carried = 0.0;
        $lapsed = 0.0;

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $closing = round($this->balances->for($employee->id, $type->id, $year), 2);

                if ($closing <= 0) {
                    continue;
                }

                $cap = $type->effectiveCarryForwardCap();
                $toCarry = round(min($closing, $cap), 2);
                $toLapse = round($closing - $toCarry, 2);

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee' => $employee->fullName(),
                    'employee_code' => $employee->employee_code,
                    'leave_type_id' => $type->id,
                    'leave_type' => $type->name,
                    'closing_balance' => $closing,
                    'cap' => $cap,
                    'carry_forward' => $toCarry,
                    'lapse' => $toLapse,
                    // Every row explains itself, so a lapse is never a silent
                    // subtraction someone discovers afterwards (C6d).
                    'explanation' => $toLapse > 0
                        ? sprintf(
                            'Closing balance %s exceeds the %s day carry-forward cap, so %s day%s will lapse on 31 December %d.',
                            self::days($closing), self::days($cap), self::days($toLapse), $toLapse == 1.0 ? '' : 's', $year,
                        )
                        : sprintf('Closing balance %s is within the %s day cap and carries forward in full.', self::days($closing), self::days($cap)),
                ];

                $employeesAffected[$employee->id] = true;
                $carried += $toCarry;
                $lapsed += $toLapse;
            }
        }

        return [
            'year' => $year,
            'rows' => $rows,
            'totals' => [
                'employees' => count($employeesAffected),
                'carried' => round($carried, 2),
                'lapsed' => round($lapsed, 2),
            ],
        ];
    }

    /**
     * Record the projection without changing anything (C6d's advance warning
     * and HR's pre-commit review both use this).
     */
    public function preview(int $year): LeaveYearEndRun
    {
        return $this->record($this->plan($year), dryRun: true);
    }

    /**
     * Post the plan. Safe to call repeatedly: entries are keyed, so a rerun
     * posts nothing new and still records that it ran.
     */
    public function commit(int $year): LeaveYearEndRun
    {
        $plan = $this->plan($year);

        return DB::transaction(function () use ($plan, $year) {
            foreach ($plan['rows'] as $row) {
                $this->post($row, $year);
            }

            return $this->record($plan, dryRun: false);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function post(array $row, int $year): void
    {
        $closeOn = Carbon::create($year, 12, 31)->toDateString();
        $openOn = Carbon::create($year + 1, 1, 1)->toDateString();
        $key = fn (string $kind) => sprintf('year_end:%d:%d:%d:%s', $year, $row['employee_id'], $row['leave_type_id'], $kind);

        if ($row['lapse'] > 0) {
            LeaveLedgerEntry::once($key('lapse'), [
                'employee_id' => $row['employee_id'],
                'leave_type_id' => $row['leave_type_id'],
                'year' => $year,
                'entry_type' => LeaveLedgerEntryType::Lapse,
                'days' => -$row['lapse'],
                'occurred_on' => $closeOn,
                'note' => $row['explanation'],
                'created_by' => Auth::id(),
            ]);
        }

        if ($row['carry_forward'] <= 0) {
            return;
        }

        // Closing the old year and opening the new one are two entries, so each
        // year's ledger sums to what that year actually finished with.
        LeaveLedgerEntry::once($key('carry_out'), [
            'employee_id' => $row['employee_id'],
            'leave_type_id' => $row['leave_type_id'],
            'year' => $year,
            'entry_type' => LeaveLedgerEntryType::CarryForward,
            'days' => -$row['carry_forward'],
            'occurred_on' => $closeOn,
            'note' => sprintf('Carried forward to %d.', $year + 1),
            'created_by' => Auth::id(),
        ]);

        LeaveLedgerEntry::once($key('carry_in'), [
            'employee_id' => $row['employee_id'],
            'leave_type_id' => $row['leave_type_id'],
            'year' => $year + 1,
            'entry_type' => LeaveLedgerEntryType::CarryForward,
            'days' => $row['carry_forward'],
            'occurred_on' => $openOn,
            'note' => sprintf('Carried forward from %d.', $year),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function record(array $plan, bool $dryRun): LeaveYearEndRun
    {
        return LeaveYearEndRun::create([
            'year' => $plan['year'],
            'is_dry_run' => $dryRun,
            'plan' => $plan['rows'],
            'employees_affected' => $plan['totals']['employees'],
            'total_carried' => $plan['totals']['carried'],
            'total_lapsed' => $plan['totals']['lapsed'],
            'run_by' => Auth::id(),
        ]);
    }

    private static function days(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
