<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePayDecision;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Modules\Hrm\Support\LeaveDayCalculator;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ 27 Oct 2026 (Joy): "Shoykod was absent for three days, on deduct from
 * sick ... their must two thing one is leave approve but unpaid leave it will
 * be, also can be approve also another approval needs to mark as paid or
 * unpaid not related with the approval of leave."
 *
 * The PAY DECISION on an approved leave, separate from approving it. The
 * authority (hrm.leave.manage, the same power that granted paid leave on 27 Sep)
 * sets how many of the leave's days are unpaid: none (Paid), all (Unpaid) or
 * some, in half-day steps, with a reason. Approving keeps its meaning: the
 * person may be away. This decides only the money.
 *
 * What it changes, and nothing else:
 *  - `unpaid_days` on the request. Payroll's Leave Without Pay line reads that
 *    one figure (PayrollContextBuilder::unpaidLeaveDays), so it stays the
 *    single source; nothing here prices a day.
 *  - On a balance type (sick, casual, earned), days made UNPAID go back to the
 *    balance, and days made PAID are drawn from it while it has them. What the
 *    balance cannot cover is the company-financed grant of 27 Sep 2026: paid,
 *    no quota (GrantPaidLeaveOverride is now this action's grant branch). A
 *    grant is undone first when days become unpaid again, so the employee's
 *    own quota is only given back once the company stops paying.
 *  - On the Unpaid Leave type, paid days are always a grant. On a paid type
 *    that draws no balance (special leave), nothing moves but the figure.
 *  - One history row (leave_pay_decisions) and the request's activity log.
 *
 * ⛔ Never after the payroll covering any of the days stops taking inputs
 * (Reviewed onward, PayrollInputWindow): that payroll is not recalculated, so
 * a change here would be a figure nobody pays. Refused in one sentence.
 * ⛔ The attendance days are not judged again: they are "on leave" either way.
 */
class DecideLeavePay
{
    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly LeaveBalance $balances,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(LeaveRequest $leave, User $by, float $unpaidDays, string $reason, bool $atApproval = false): LeaveRequest
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the pay on this leave changes.']);
        }

        return DB::transaction(function () use ($leave, $by, $unpaidDays, $reason, $atApproval) {
            $current = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $current->load(['leaveType', 'employee']);

            $days = round((float) $current->days, 2);
            $before = round((float) $current->unpaid_days, 2);
            $after = round($unpaidDays, 2);

            if (($refusal = self::refusal($current)) !== null) {
                throw ValidationException::withMessages(['status' => $refusal]);
            }

            if ($after < 0 || $after > $days || abs($after * 2 - round($after * 2)) > 0.001) {
                throw ValidationException::withMessages([
                    'unpaid_days' => 'Unpaid days must be between 0 and '.self::trim($days).', in half-day steps.',
                ]);
            }

            if (abs($after - $before) < 0.001) {
                throw ValidationException::withMessages([
                    'unpaid_days' => 'This leave is already '.lcfirst(self::label($days, $before)).'. Nothing changes.',
                ]);
            }

            $type = $current->leaveType;
            $drawn = $this->drawnByYear($current);
            $paidBefore = round($days - $before, 2);
            $paidAfter = round($days - $after, 2);
            $grantedBefore = $this->granted($current, $paidBefore, $drawn);

            $decision = LeavePayDecision::create([
                'leave_request_id' => $current->id,
                'unpaid_days_before' => $before,
                'unpaid_days_after' => $after,
                'at_approval' => $atApproval,
                'reason' => trim($reason),
                'decided_by' => $by->id,
            ]);

            $quota = 0.0;
            $grantedAfter = $grantedBefore;

            if ($type?->deducts_balance) {
                if ($paidAfter < $paidBefore) {
                    $less = round($paidBefore - $paidAfter, 2);
                    $fromGrant = min($less, $grantedBefore);
                    $grantedAfter = round($grantedBefore - $fromGrant, 2);
                    $quota = $this->giveBack($current, $decision, round($less - $fromGrant, 2), $drawn, $by);
                } else {
                    $more = round($paidAfter - $paidBefore, 2);
                    $taken = $this->draw($current, $decision, $more, $drawn, $by);
                    $quota = -$taken;
                    $grantedAfter = round($grantedBefore + $more - $taken, 2);
                }
            } elseif ($type !== null && ! $type->is_paid) {
                $grantedAfter = $paidAfter;
            } else {
                $grantedAfter = 0.0;
            }

            $decision->update(['quota_days' => round($quota, 2), 'granted_days' => max(0.0, $grantedAfter)]);

            $current->update([
                'unpaid_days' => $after,
                'paid_override_by' => $grantedAfter > 0 ? $by->id : null,
                'paid_override_at' => $grantedAfter > 0 ? now() : null,
                'paid_override_reason' => $grantedAfter > 0 ? trim($reason) : null,
            ]);

            return $current->fresh();
        });
    }

    /**
     * Why the pay on this leave cannot be decided now, or null. Shared by the
     * screen (the button greys out with this sentence) and the action.
     */
    public static function refusal(LeaveRequest $leave): ?string
    {
        if ($leave->isCancelled()) {
            return 'This leave is cancelled, so it has no pay to decide.';
        }

        if (! $leave->isApplied()) {
            return 'Only an approved leave has a pay decision. Choose the pay on the approval card, or approve it first.';
        }

        foreach ($leave->covered_dates ?? [] as $date) {
            $period = PayrollInputWindow::periodContaining($date);

            if ($period !== null && ! PayrollInputWindow::accepts($period)) {
                return sprintf(
                    'Payroll for %s is already %s, so the pay on this leave can no longer change. Put any difference on the next open payroll (Payroll, Inputs).',
                    $period->label(),
                    strtolower($period->latestRun()->status->label()),
                );
            }
        }

        return null;
    }

    /** "Paid", "Unpaid", or "2 of 3 days unpaid". */
    public static function label(float $days, float $unpaid): string
    {
        if ($unpaid <= 0.001) {
            return 'Paid';
        }

        if ($unpaid >= $days - 0.001) {
            return 'Unpaid';
        }

        return sprintf('%s of %s days unpaid', self::trim($unpaid), self::trim($days));
    }

    /**
     * Days of quota this leave holds now, by year: every ledger row that names
     * it (taken, pay decisions), as a positive figure.
     *
     * @return array<int, float>
     */
    private function drawnByYear(LeaveRequest $leave): array
    {
        return LeaveLedgerEntry::query()
            ->where('reference_type', $leave->getMorphClass())
            ->where('reference_id', $leave->id)
            ->selectRaw('year, sum(days) as total')
            ->groupBy('year')
            ->pluck('total', 'year')
            ->map(fn ($total) => round(-(float) $total, 2))
            ->all();
    }

    /** Paid days the company pays because no quota stands behind them. */
    private function granted(LeaveRequest $leave, float $paid, array $drawn): float
    {
        $type = $leave->leaveType;

        if ($type?->deducts_balance) {
            return round(max(0.0, $paid - array_sum($drawn)), 2);
        }

        return $type !== null && ! $type->is_paid ? $paid : 0.0;
    }

    /** @param  array<int, float>  $drawn */
    private function giveBack(LeaveRequest $leave, LeavePayDecision $decision, float $days, array $drawn, User $by): float
    {
        $remaining = $days;
        $given = 0.0;
        krsort($drawn);

        foreach ($drawn as $year => $held) {
            $give = round(min($remaining, max(0.0, $held)), 2);

            if ($give <= 0) {
                continue;
            }

            $this->refuseClosedYear((int) $year);
            $this->post($leave, $decision, (int) $year, $give, $by, 'Made unpaid, days back to the balance');
            $remaining = round($remaining - $give, 2);
            $given += $give;
        }

        return round($given, 2);
    }

    /** @param  array<int, float>  $drawn */
    private function draw(LeaveRequest $leave, LeavePayDecision $decision, float $days, array $drawn, User $by): float
    {
        $remaining = $days;
        $taken = 0.0;

        foreach ($this->calculator->daysByYear($leave->covered_dates ?? [], (float) $leave->days) as $year => $yearDays) {
            $room = round($yearDays - ($drawn[$year] ?? 0.0), 2);
            $available = max(0.0, $this->balances->for($leave->employee_id, $leave->leave_type_id, $year));
            $take = round(max(0.0, min($remaining, $room, $available)), 2);

            if ($take <= 0) {
                continue;
            }

            $this->refuseClosedYear((int) $year);
            $this->post($leave, $decision, (int) $year, -$take, $by, 'Made paid, drawn from the balance');
            $remaining = round($remaining - $take, 2);
            $taken += $take;
        }

        return round($taken, 2);
    }

    private function post(LeaveRequest $leave, LeavePayDecision $decision, int $year, float $days, User $by, string $what): void
    {
        $occurredOn = collect($leave->covered_dates ?? [])->first(fn (string $d) => (int) substr($d, 0, 4) === $year)
            ?? Carbon::parse($leave->start_date)->toDateString();

        LeaveLedgerEntry::once("leave_pay:{$decision->id}:{$year}", [
            'employee_id' => $leave->employee_id,
            'leave_type_id' => $leave->leave_type_id,
            'year' => $year,
            'entry_type' => LeaveLedgerEntryType::PayDecision,
            'days' => $days,
            'occurred_on' => $occurredOn,
            'note' => "{$what}: {$leave->summaryLine()}. Reason: {$decision->reason}",
            'reference_type' => $leave->getMorphClass(),
            'reference_id' => $leave->id,
            'created_by' => $by->id,
        ]);
    }

    private function refuseClosedYear(int $year): void
    {
        // Same test as LeaveRequestActionPolicy::closedYear: a committed year-end.
        if (LeaveYearEndRun::query()->where('year', $year)->where('is_dry_run', false)->exists()) {
            throw ValidationException::withMessages([
                'status' => "The {$year} leave year-end is done, so its balance can no longer change.",
            ]);
        }
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
