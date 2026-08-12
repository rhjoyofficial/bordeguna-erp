<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveProvisionRun;
use App\Modules\Hrm\Support\LeaveProvisionValuation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The leave encashment provision true-up (coverage item 5).
 *
 * ⚠⚠ PLAN EQUALS COMMIT. `plan()` is the only place any decision is made and
 * `commit()` posts exactly what it returned - the LeaveYearEndProcessor shape,
 * so what HR reviewed is what lands. There is no second code path that could
 * drift from the preview.
 *
 * ⚠⚠ A TRUE-UP, not an accrual stream. Every leave event already lives in the
 * leave ledger; this computes the WHOLE liability and posts only the difference
 * against the provision account's current balance. A skipped month, a raise, a
 * lapse or a data correction all land correctly on the next run, because the
 * run always aims at the computed truth rather than at an increment.
 *
 * ⚠ Re-running on the same day is harmless by construction: the second run
 * computes a delta of zero and posts nothing.
 */
class RunLeaveProvision
{
    public function __construct(
        private readonly PostLeaveProvision $posting,
        private readonly BusinessContext $context,
    ) {}

    /**
     * What the provision would be at `$on` - the single source of truth for
     * both the preview and the commit.
     *
     * @return array{
     *     valued_on: string,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{employees: int, liability: float, unpriced: int}
     * }
     */
    public function plan(mixed $on = null): array
    {
        $date = ($on === null ? Carbon::now() : Carbon::parse($on))->startOfDay();
        $valuation = LeaveProvisionValuation::make();

        // ⚠ Active employees with a PRIMARY assignment in this business on the
        // valuation date. A leaver's days settle through their settlement (or a
        // manual journal) and their provision drains via the next true-up -
        // continuing to provide for someone who has gone would overstate the
        // liability forever.
        $employees = Employee::query()
            ->whereNull('separation_date')
            ->whereHas('assignments', fn ($query) => $query
                ->where('business_id', $this->context->id())
                ->activeOn($date)
                ->where('is_primary', true))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();

        $rows = [];
        $liability = 0.0;
        $affected = 0;
        $unpriced = 0;

        foreach ($employees as $employee) {
            $figure = $valuation->for($employee, $date);

            if ($figure['days'] <= 0) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'days' => $figure['days'],
                'daily_rate' => $figure['rate'],
                'amount' => $figure['amount'],
                'unpriced' => $figure['unpriced'],
                // Every row explains itself, so a liability is never a figure
                // somebody has to reverse-engineer months later.
                'explanation' => $figure['unpriced']
                    ? sprintf(
                        '%s encashable day(s) could NOT be priced: no salary structure in force on %s.',
                        self::days($figure['days']), $date->format('d M Y'),
                    )
                    : sprintf(
                        '%s encashable day(s) at %s per day (Gross divided by the payroll divisor).',
                        self::days($figure['days']), number_format((float) $figure['rate'], 2),
                    ),
            ];

            $affected++;
            $liability += $figure['amount'];

            if ($figure['unpriced']) {
                $unpriced++;
            }
        }

        return [
            'valued_on' => $date->toDateString(),
            'rows' => $rows,
            'totals' => [
                'employees' => $affected,
                'liability' => round($liability, 2),
                'unpriced' => $unpriced,
            ],
        ];
    }

    /**
     * Record the projection without changing anything.
     */
    public function preview(mixed $on = null): LeaveProvisionRun
    {
        $plan = $this->plan($on);

        return $this->record($plan, $this->posting->currentBalance($plan['valued_on']), dryRun: true, runBy: null);
    }

    /**
     * Post the delta between the computed liability and what the provision
     * account already carries.
     */
    public function commit(User $runBy, mixed $on = null, ?string $note = null): LeaveProvisionRun
    {
        $plan = $this->plan($on);

        return DB::transaction(function () use ($plan, $runBy, $note) {
            $opening = $this->posting->currentBalance($plan['valued_on']);
            $run = $this->record($plan, $opening, dryRun: false, runBy: $runBy, note: $note);

            // Posted in the same transaction as the document, like every other
            // Phase 3 integration.
            $this->posting->forRun($run->fresh(), $runBy);

            return $run->fresh();
        });
    }

    private function record(array $plan, float $opening, bool $dryRun, ?User $runBy, ?string $note = null): LeaveProvisionRun
    {
        $liability = $plan['totals']['liability'];

        return LeaveProvisionRun::create([
            'business_id' => $this->context->id(),
            'valued_on' => $plan['valued_on'],
            'is_dry_run' => $dryRun,
            'plan' => $plan['rows'],
            'employees_affected' => $plan['totals']['employees'],
            'total_liability' => number_format($liability, 4, '.', ''),
            'opening_balance' => number_format($opening, 4, '.', ''),
            'delta' => number_format(round($liability - $opening, 2), 4, '.', ''),
            'note' => $note,
            'run_by' => $runBy?->id ?? $this->anyUserId(),
        ]);
    }

    /**
     * ⚠ A preview has no acting user in a console context, and `run_by` is not
     * nullable on purpose: every run must be attributable. Falls back to the
     * first user rather than making the column optional, because a nullable
     * author is the thing that makes an audit trail useless later.
     */
    private function anyUserId(): int
    {
        return (int) (auth()->id() ?? User::query()->orderBy('id')->value('id'));
    }

    private static function days(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
