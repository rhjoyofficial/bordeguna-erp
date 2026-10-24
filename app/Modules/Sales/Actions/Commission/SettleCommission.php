<?php

namespace App\Modules\Sales\Actions\Commission;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Hrm\Support\PayrollInputWindow;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Support\CommissionHeads;
use App\Modules\Sales\Support\CommissionTracker;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Settle a commission agreement through a day (Joy's Q18, 25 Oct 2026).
 *
 * The amount is never typed: it is everything the agreement earned from its
 * start through `through`, less what live settlements already took, worked
 * out again under a lock on the agreement (so a double click, or two people
 * at once, settle it once).
 *
 *   payable    Dr Sales Commission / Cr Commission Payable, the person on the line
 *   paid_now   Dr Sales Commission (the person) / Cr the money account
 *   payroll    an Addition on the first payroll month that still takes inputs
 *              (employees only); payroll posts it, nothing posts here
 *
 * ⚠ Above the Finance policy's second-approval limit it waits for a second
 * person (the voucher workflow) and posts when approved; self-approval is
 * allowed with a reason (Joy, 10 Sep). Reverse is the corrections engine's.
 */
class SettleCommission
{
    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly NotifyStageApprovers $notify,
        private readonly CommissionTracker $tracker,
    ) {}

    public function handle(CommissionAgreement $agreement, string $settledOn, string $through, string $method, ?MoneyAccount $moneyAccount, ?string $note, User $by): CommissionSettlement
    {
        $on = Carbon::parse($settledOn)->startOfDay();
        $throughDay = Carbon::parse($through)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['settled_on' => 'A settlement cannot be dated in the future.']);
        }

        if ($throughDay->gt($on)) {
            throw ValidationException::withMessages(['through_date' => 'Settle sales up to the settlement date, not after it.']);
        }

        AccountingPeriod::assertOpenFor($on);

        if ($method === 'payroll' && $agreement->party_type !== 'employee') {
            throw ValidationException::withMessages(['method' => 'Only an employee can be paid through payroll.']);
        }

        if ($method === 'paid_now' && $moneyAccount === null) {
            throw ValidationException::withMessages(['money_account_id' => 'Choose the account the money is paid from.']);
        }

        $settlement = DocumentNumber::claim('number', fn () => $this->nextNumber((int) $agreement->business_id), function (string $number) use ($agreement, $on, $throughDay, $method, $moneyAccount, $note, $by) {
            /** @var CommissionAgreement $agreement */
            $agreement = CommissionAgreement::query()->whereKey($agreement->id)->lockForUpdate()->with(['items', 'tiers'])->firstOrFail();

            if (! $agreement->isActive()) {
                throw ValidationException::withMessages(['agreement' => "{$agreement->number} is {$agreement->statusLabel()}, so nothing can be settled on it."]);
            }

            if ($throughDay->lt($agreement->valid_from)) {
                throw ValidationException::withMessages(['through_date' => "{$agreement->number} starts on {$agreement->valid_from->format('d/m/Y')}."]);
            }

            $last = $this->tracker->lastSettledThrough($agreement);

            if ($last !== null && $throughDay->lte($last)) {
                throw ValidationException::withMessages(['through_date' => "Sales through {$last->format('d/m/Y')} are already settled. Choose a later day."]);
            }

            $counted = $agreement->lastCountingDay($throughDay);
            $sold = $this->tracker->measure($agreement, $agreement->valid_from->copy(), $counted);
            $earned = $this->tracker->earnedFor($agreement, $sold['quantity'], $sold['value']);
            $amount = $earned->plus($this->tracker->settled($agreement)->negated());

            if ($amount->minor <= 0) {
                throw ValidationException::withMessages(['through_date' => "Nothing is left to settle on {$agreement->number} through {$throughDay->format('d/m/Y')}: earned Tk {$earned->format()}, already settled Tk {$this->tracker->settled($agreement)->format()}."]);
            }

            if ($method === 'paid_now') {
                MoneyAvailability::assertCanPay($moneyAccount, $amount, $on, 'money_account_id');
            }

            $from = $last === null ? $agreement->valid_from->copy() : $last->copy()->addDay();
            $window = $this->tracker->measure($agreement, $from, $counted);

            $settlement = CommissionSettlement::create([
                'business_id' => $agreement->business_id,
                'number' => $number,
                'commission_agreement_id' => $agreement->id,
                'settled_on' => $on,
                'from_date' => $from,
                'through_date' => $throughDay,
                'sold_quantity' => $window['quantity'],
                'sold_value' => $window['value'],
                'earned_to_date' => $earned->toTaka(),
                'amount' => $amount->toTaka(),
                'method' => $method,
                'money_account_id' => $method === 'paid_now' ? $moneyAccount->id : null,
                'note' => $note,
                'status' => CommissionSettlement::PENDING,
                'created_by' => $by->id,
            ]);

            $limit = FinancePolicy::for((int) $agreement->business_id)->money('payment_second_approval_above_minor');

            if ($limit->minor > 0 && $amount->minor > $limit->minor) {
                $settlement->submitForApproval($by);

                return $settlement;
            }

            $this->post($settlement, $by);

            return $settlement;
        });

        $settlement = $settlement->fresh(['agreement', 'approvalRequest']);

        if ($settlement->isPending() && $settlement->approvalRequest !== null) {
            $this->notify->handle($settlement->approvalRequest, "Commission settlement {$settlement->number} ({$settlement->agreement->partyName()}, Tk {$settlement->money()->format()}) is waiting for your approval");
        }

        return $settlement;
    }

    public function approve(CommissionSettlement $settlement, User $by, ?string $note = null): bool
    {
        $approval = $this->pendingRequest($settlement, $by);

        return DB::transaction(function () use ($settlement, $approval, $by, $note) {
            $settlement = CommissionSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if (! $settlement->isPending()) {
                throw ValidationException::withMessages(['settlement' => "{$settlement->number} is no longer waiting for approval."]);
            }

            if (! $approval->approve($by, $note)) {
                return false;
            }

            $this->post($settlement, $by);

            return true;
        });
    }

    public function reject(CommissionSettlement $settlement, User $by, ?string $note): void
    {
        $approval = $this->pendingRequest($settlement, $by);

        DB::transaction(function () use ($settlement, $approval, $by, $note) {
            $approval->reject($by, $note);
            $settlement->update(['status' => CommissionSettlement::REJECTED, 'decision_note' => $note]);
        });
    }

    /** The posting, inside the caller's transaction. */
    private function post(CommissionSettlement $settlement, User $by): void
    {
        $settlement->loadMissing(['agreement.employee', 'agreement.customer', 'agreement.supplier', 'moneyAccount']);
        $agreement = $settlement->agreement;
        $party = $agreement->party();
        $amount = $settlement->money();
        $memo = "Commission {$settlement->number} on {$agreement->number} for {$agreement->partyName()}, sales {$settlement->from_date->format('d/m/Y')} to {$settlement->through_date->format('d/m/Y')}";
        $journalId = null;
        $adjustmentId = null;

        if ($settlement->method === 'payroll') {
            $adjustment = PayrollAdjustment::once("commission_settlement:{$settlement->id}", [
                'business_id' => $settlement->business_id,
                'employee_id' => $agreement->employee_id,
                'payroll_period_id' => $this->openPayrollMonth($settlement->settled_on)->id,
                'type' => PayrollAdjustmentType::Addition,
                'amount' => $amount->toTaka(),
                'reason' => $memo,
                'origin_type' => $settlement->getMorphClass(),
                'origin_id' => $settlement->id,
                'requested_by' => $by->id,
            ]) ?? throw ValidationException::withMessages(['settlement' => "{$settlement->number} is already on a payroll."]);
            $adjustmentId = $adjustment->id;
        } else {
            $credit = $settlement->method === 'paid_now'
                ? JournalLineDraft::credit($settlement->moneyAccount->account_id, $amount, $settlement->moneyAccount->label(), $party, $settlement->moneyAccount)
                : JournalLineDraft::credit(CommissionHeads::payable()->id, $amount, 'Owed to '.$agreement->partyName(), $party);

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $settlement->settled_on->copy(),
                lines: [JournalLineDraft::debit(CommissionHeads::expense()->id, $amount, $memo, $party), $credit],
                memo: $memo,
                source: $settlement,
            ), $by);
            $journalId = $journal->id;
        }

        $settlement->forceFill([
            'status' => CommissionSettlement::POSTED,
            'journal_id' => $journalId,
            'payroll_adjustment_id' => $adjustmentId,
            'posted_by' => $by->id,
            'posted_at' => now(),
        ])->save();
    }

    /** The payroll month the settlement day falls in, or the first later one that still takes inputs. */
    private function openPayrollMonth(Carbon $day): PayrollPeriod
    {
        $cycle = PayrollCycle::containing($day);
        $candidate = PayrollPeriod::forMonth($cycle['year'], $cycle['month']);

        for ($step = 0; $step < 24; $step++) {
            if (PayrollInputWindow::accepts($candidate)) {
                return $candidate;
            }

            $next = Carbon::create($candidate->year, $candidate->month, 1)->addMonthNoOverflow();
            $candidate = PayrollPeriod::forMonth($next->year, $next->month);
        }

        PayrollInputWindow::assertAccepts($candidate, 'method');

        return $candidate;
    }

    private function pendingRequest(CommissionSettlement $settlement, User $by): ApprovalRequest
    {
        $approval = $settlement->approvalRequest;

        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return $approval;
    }

    private function nextNumber(int $businessId): string
    {
        $count = CommissionSettlement::query()->withoutGlobalScopes()->where('business_id', $businessId)->count();

        return 'CS-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
