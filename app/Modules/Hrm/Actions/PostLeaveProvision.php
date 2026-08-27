<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveProvisionRun;
use Illuminate\Support\Carbon;

/**
 * The liability for leave the staff have earned and not taken.
 *
 * ```
 * Liability grew:
 * Dr  Leave Encashment (6130)              the delta
 *     Cr  Leave Encashment Provision       (party = employee, one line each)
 *
 * Liability shrank (days taken, lapsed, staff left, a rate correction):
 * the exact reverse.
 * ```
 *
 * ⚠⚠ PER-EMPLOYEE LINES ON THE PROVISION SIDE make it a subsidiary ledger, so
 * "what do we owe this person for leave" is a ledger balance rather than a
 * number somebody recomputes. The expense side is one line: the wage bill does
 * not need a party.
 *
 * ⚠ Each employee's line is their own DELTA - their computed liability less
 * what the provision account already carries for them - so the per-party
 * balances stay correct even when the total happens not to move.
 *
 * ⚠ Lives in HRM. The ledger never learns what a leave day is.
 */
class PostLeaveProvision
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * What the provision account carries today, as a positive liability.
     */
    public function currentBalance(mixed $on = null): float
    {
        $date = ($on === null ? Carbon::now() : Carbon::parse($on))->startOfDay();
        $account = AccountMapping::forKeyOn('payroll.leave_provision', $date)?->account;

        if ($account === null) {
            return 0.0;
        }

        // Credit-normal: stored signed, so a liability is negative in the sum.
        return round(-((int) JournalLine::query()->where('account_id', $account->id)->sum('amount_minor')) / 100, 2);
    }

    /**
     * ⚠⚠ DISABLED BY BUSINESS POLICY, 23 Aug 2026 - JOY'S FINAL DECISION.
     * Leave encashment is not enabled at Bor de Guna: unused leave creates no
     * financial liability and no provision journal, and the ~133,566.67 BDT
     * this engine computes must never be posted unless he explicitly changes
     * the policy. Leave balances and expiry stay, for HR purposes only.
     *
     * The engine and the valuation stay in code because the policy could
     * change; the POSTING is what the policy forbids, so the posting is what
     * refuses. Deleting the whole feature to express a policy would throw
     * away the half that still works.
     */
    public const POSTING_DISABLED_REASON = 'Leave encashment is not enabled at this business, so no provision is posted. Unused leave carries no financial liability (decided 23 Aug 2026). Leave balances remain tracked for HR.';

    public function forRun(LeaveProvisionRun $run, User $postedBy): ?Journal
    {
        throw new \RuntimeException(self::POSTING_DISABLED_REASON);
    }

    /**
     * The pre-policy posting path, unreachable while the refusal above stands.
     * Kept whole so re-enabling the policy is one method rename, reviewed,
     * rather than an archaeology dig.
     */
    private function forRunWhenPolicyAllows(LeaveProvisionRun $run, User $postedBy): ?Journal
    {
        if ($run->is_dry_run || $this->existingJournal($run) !== null) {
            return null;
        }

        $on = Carbon::parse($run->valued_on)->startOfDay();

        // The same anchor as every other payroll posting: a business that has
        // not switched accounting on still runs its leave book exactly as
        // before, and this simply records nothing.
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        $delta = $run->deltaMoney();

        if ($delta->isZero()) {
            return null;
        }

        $expense = AccountMapping::resolve('payroll.leave_encashment', $on);
        $provision = AccountMapping::resolve('payroll.leave_provision', $on);

        $lines = [];

        foreach ($this->perEmployeeDeltas($run, $provision->id) as [$employee, $amount]) {
            if ($amount->isZero()) {
                continue;
            }

            // ⚠ A row with no employee is the unattributed remainder, and it
            // says so on the line: the one figure here a person cannot trace to
            // a named colleague should not look like the ones they can.
            $released = $employee === null
                ? 'Leave provision released (balance not attributed to an employee)'
                : 'Leave provision released';

            $lines[] = $amount->minor > 0
                ? JournalLineDraft::credit($provision->id, $amount, 'Leave provision', $employee)
                : JournalLineDraft::debit($provision->id, $amount->absolute(), $released, $employee);
        }

        if ($lines === []) {
            return null;
        }

        // The expense side, one line, balancing the per-employee movement.
        $lines[] = $delta->minor > 0
            ? JournalLineDraft::debit($expense->id, $delta, 'Leave earned not taken')
            : JournalLineDraft::credit($expense->id, $delta->absolute(), 'Leave provision released');

        $lines = LedgerRounding::balance($lines, $on, 'Rounding on leave provision '.$on->toDateString());

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Leave encashment provision as at '.$on->format('d M Y'),
            source: $run,
        ), $postedBy);
    }

    /**
     * Each employee's movement: their computed liability less what the ledger
     * already holds for them.
     *
     * ⚠⚠ THE DELTAS MUST SUM TO THE RUN'S TOTAL DELTA. The run's delta is the
     * computed liability less the WHOLE account balance, while the per-employee
     * figures only ever see PARTY-TAGGED lines. Anything sitting on the account
     * without a party - C-LPE-4's pre-cutover catch-up, or the manual journal
     * this design offers for a surprise leaver - falls in the gap between those
     * two views, and `LedgerRounding` would quietly absorb it: a real amount of
     * payroll expense filed as a rounding difference, with the provision
     * account left overstated by exactly that much.
     *
     * So the unattributed remainder is trued up as its own party-less row. That
     * is not a special case bolted on; it is the design's own rule applied
     * honestly - the run aims the account at the computed truth, and money that
     * belongs to nobody in particular cannot be defended per person, so the
     * true-up releases it. An amount genuinely owed to a named leaver carries
     * their party and drains through their own row instead.
     *
     * @return array<int, array{0: Employee|null, 1: Money}>
     */
    private function perEmployeeDeltas(LeaveProvisionRun $run, int $accountId): array
    {
        $morphClass = (new Employee)->getMorphClass();

        $ledger = JournalLine::query()
            ->where('account_id', $accountId)
            ->where('party_type', $morphClass)
            ->groupBy('party_id')
            ->selectRaw('party_id, SUM(amount_minor) as total_minor')
            ->pluck('total_minor', 'party_id')
            ->map(fn ($minor) => Money::ofMinor(-(int) $minor));

        $computed = [];

        foreach ($run->plan ?? [] as $row) {
            $computed[(int) $row['employee_id']] = Money::ofTaka(number_format((float) $row['amount'], 4, '.', ''));
        }

        $ids = array_unique([...array_keys($computed), ...array_keys($ledger->all())]);
        $employees = Employee::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');

        $deltas = [];

        foreach ($ids as $id) {
            $want = $computed[$id] ?? Money::zero();
            $have = $ledger[$id] ?? Money::zero();

            $deltas[] = [$employees[$id] ?? null, Money::ofMinor($want->minor - $have->minor)];
        }

        // ⚠ Whatever the account carries that no employee line accounts for.
        // Computed against the same total `currentBalance()` used for the run's
        // opening balance, so the two views close on each other by construction.
        $accountMinor = -(int) JournalLine::query()->where('account_id', $accountId)->sum('amount_minor');
        $attributedMinor = array_sum(array_map(fn (Money $money) => $money->minor, $ledger->values()->all()));
        $unattributed = Money::ofMinor($accountMinor - $attributedMinor);

        if (! $unattributed->isZero()) {
            $deltas[] = [null, Money::ofMinor(-$unattributed->minor)];
        }

        return $deltas;
    }

    public function existingJournal(LeaveProvisionRun $run): ?Journal
    {
        return Journal::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
