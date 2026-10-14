<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanLedger;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanPosting;
use App\Modules\Accounting\Support\Loans\LoanStatusSync;
use App\Modules\Accounting\Support\Loans\RepaymentPlan;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Principal write-off and interest waiver (loans plan v2, phase 2): asked for
 * with a reason and evidence, approved by the Owner, posted on approval.
 *
 *   PRINCIPAL WRITE-OFF (loans given only)
 *     Dr 6406 Bad Debts Written Off / Cr 11754 then the principal head (counterparty, loan)
 *
 *   INTEREST WAIVER
 *     accrued interest given up   given: Dr 4920 / Cr 11753 (counterparty, loan)
 *                                 taken: Dr 2127 (counterparty, loan) / Cr 6478
 *     interest not yet accrued    forgone on the schedule; no journal, nothing
 *                                 was ever booked for it
 *
 * ⚠ Never hidden inside a repayment, and never one for the other.
 */
class LoanWriteOffs
{
    public function __construct(
        private readonly LoanApprovals $approvals,
        private readonly LoanPosting $posting,
        private readonly StoreAttachment $attachments,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $data  kind, amount, forgo, effective_date, reason
     * @param  list<UploadedFile>  $evidence
     */
    public function request(LoanContract $loan, array $data, array $evidence, User $by): LoanWriteOff
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, [LoanWriteOff::PRINCIPAL, LoanWriteOff::INTEREST], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a principal write-off or an interest waiver.']);
        }

        if ($loan->status !== LoanStatus::Active) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}: there is nothing to write off."]);
        }

        if ($kind === LoanWriteOff::PRINCIPAL && $loan->direction !== LoanDirection::Given) {
            throw ValidationException::withMessages(['kind' => 'Only money the business lent is written off. A lender forgiving what the business owes is not a write-off here; ask the accountant how to book it.']);
        }

        if (LoanWriteOff::query()->where('loan_contract_id', $loan->id)->where('status', LoanWriteOff::PENDING)->exists()) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} already has a write-off or waiver waiting for the Owner."]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why, and who agreed it.']);
        }

        if ($evidence === []) {
            throw ValidationException::withMessages(['evidence' => 'Attach the evidence: the settlement letter, the court or recovery papers, the lender\'s waiver.']);
        }

        $date = LoanPosting::date($data['effective_date'] ?? null, $loan, 'effective_date');
        $position = LoanPosition::of($loan);
        $amount = $this->money($data['amount'] ?? '0', 'amount');
        $forgo = $kind === LoanWriteOff::INTEREST ? $this->money($data['forgo'] ?? '0', 'forgo') : 0;

        if ($kind === LoanWriteOff::PRINCIPAL) {
            if ($amount <= 0 || $amount > max(0, $position->principal)) {
                throw ValidationException::withMessages(['amount' => 'Write off between nothing and the principal outstanding ('.Money::ofMinor(max(0, $position->principal))->format().').']);
            }
        } else {
            if ($amount > max(0, $position->accrued)) {
                throw ValidationException::withMessages(['amount' => 'Only interest already accrued can be reversed ('.Money::ofMinor(max(0, $position->accrued))->format().' is accrued).']);
            }

            if ($forgo > $position->unpaidScheduledInterest()) {
                throw ValidationException::withMessages(['forgo' => 'That is more than the interest still on the schedule ('.Money::ofMinor($position->unpaidScheduledInterest())->format().').']);
            }

            if ($amount + $forgo <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the interest being waived.']);
            }
        }

        $writeOff = new LoanWriteOff([
            'business_id' => $loan->business_id,
            'loan_contract_id' => $loan->id,
            'kind' => $kind,
            'amount_minor' => $amount,
            'forgo_minor' => $forgo,
            'effective_date' => $date->toDateString(),
            'reason' => $reason,
            'status' => LoanWriteOff::PENDING,
            'created_by' => $by->id,
        ]);

        $this->approvals->assertWorkflow($writeOff, 'write-offs and waivers');

        $request = DB::transaction(function () use ($writeOff, $evidence, $by, $loan) {
            $writeOff->save();

            foreach ($evidence as $file) {
                $this->attachments->handle($writeOff, $file, LoanWriteOff::EVIDENCE_COLLECTION, $by);
            }

            activity()->performedOn($loan)->causedBy($by)->withProperties(['write_off_id' => $writeOff->id])->log("Asked the Owner for a {$writeOff->label()} on {$loan->loan_number}");

            return $writeOff->submitForApproval($by);
        });

        $this->notify->handle($request, "{$writeOff->label()} on loan {$loan->loan_number} is waiting for your approval");

        return $writeOff;
    }

    public function approve(LoanWriteOff $writeOff, User $by, ?string $note = null): bool
    {
        $approval = $writeOff->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return DB::transaction(function () use ($writeOff, $approval, $by, $note) {
            if (! $approval->approve($by, $note)) {
                return false;
            }

            $this->post($writeOff->fresh(), $by);

            return true;
        });
    }

    public function reject(LoanWriteOff $writeOff, User $by, ?string $note): void
    {
        $approval = $writeOff->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        DB::transaction(function () use ($writeOff, $approval, $by, $note) {
            $approval->reject($by, $note);
            $writeOff->update(['status' => LoanWriteOff::REJECTED]);
            activity()->performedOn($writeOff->loan)->causedBy($by)->withProperties(['note' => $note])->log("{$writeOff->label()} turned down");
        });
    }

    private function post(LoanWriteOff $writeOff, User $by): void
    {
        $loan = LoanContract::query()->with('counterparty')->findOrFail($writeOff->loan_contract_id);

        if ($writeOff->status !== LoanWriteOff::PENDING) {
            throw ValidationException::withMessages(['loan' => 'This was already decided.']);
        }

        try {
            $date = AccountingPeriod::firstOpenOnOrAfter($writeOff->effective_date->copy());
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['loan' => $e->getMessage()]);
        }

        $party = $loan->counterparty;
        $amount = (int) $writeOff->amount_minor;
        $forgo = (int) $writeOff->forgo_minor;
        $memo = "{$writeOff->label()} - {$loan->loan_number} {$party->legal_name}: {$writeOff->reason}";

        if ($writeOff->isPrincipal()) {
            $position = LoanPosition::of($loan);

            if ($amount > max(0, $position->principal)) {
                throw ValidationException::withMessages(['loan' => 'Less principal is owed now than when this was asked for. Ask again.']);
            }

            $fromCurrent = min($amount, max(0, LoanLedger::owedOn($loan, $loan->current_portion_account_id === null ? null : (int) $loan->current_portion_account_id)));
            $lines = [JournalLineDraft::debit($this->head('bad_debts')->id, Money::ofMinor($amount), $memo)];

            if ($fromCurrent > 0) {
                $lines[] = JournalLineDraft::credit((int) $loan->current_portion_account_id, Money::ofMinor($fromCurrent), $memo, $party, null, $loan->id);
            }

            if ($amount - $fromCurrent > 0) {
                $lines[] = JournalLineDraft::credit((int) $loan->principal_account_id, Money::ofMinor($amount - $fromCurrent), $memo, $party, null, $loan->id);
            }

            $this->posting->post($loan, LoanEntryKind::WriteOff, $date, $lines, [
                'principal_minor' => -$amount,
                'loan_write_off_id' => $writeOff->id,
                'note' => $writeOff->reason,
            ], $memo, $by, fn (LoanEntry $entry, LoanPosition $before) => RepaymentPlan::allocate($entry, $before, $amount, 0));
        } elseif ($amount > 0) {
            $position = LoanPosition::of($loan);

            if ($amount > max(0, $position->accrued)) {
                throw ValidationException::withMessages(['loan' => 'Less interest is accrued now than when this was asked for. Ask again.']);
            }

            $given = $loan->direction === LoanDirection::Given;
            $lines = $given
                ? [
                    JournalLineDraft::debit((int) $loan->interest_pl_account_id, Money::ofMinor($amount), $memo),
                    JournalLineDraft::credit((int) $loan->interest_accrual_account_id, Money::ofMinor($amount), $memo, $party, null, $loan->id),
                ]
                : [
                    JournalLineDraft::debit((int) $loan->interest_accrual_account_id, Money::ofMinor($amount), $memo, $party, null, $loan->id),
                    JournalLineDraft::credit((int) $loan->interest_pl_account_id, Money::ofMinor($amount), $memo),
                ];

            $this->posting->post($loan, LoanEntryKind::Waiver, $date, $lines, [
                'accrued_interest_minor' => -$amount,
                'interest_pl_minor' => -$amount,
                'loan_write_off_id' => $writeOff->id,
                'note' => $writeOff->reason,
            ], $memo, $by, function (LoanEntry $entry, LoanPosition $before) use ($amount, $forgo, $writeOff, $loan) {
                RepaymentPlan::allocate($entry, $before, 0, $amount);

                if ($forgo > 0) {
                    RepaymentPlan::allocate($entry, LoanPosition::of($loan), 0, $forgo, $writeOff->effective_date->copy());
                }
            });
        } else {
            // Interest not yet accrued: forgone on the schedule only.
            $entry = LoanEntry::query()->create([
                'business_id' => $loan->business_id,
                'loan_contract_id' => $loan->id,
                'kind' => LoanEntryKind::Waiver,
                'entry_date' => $date->toDateString(),
                'loan_write_off_id' => $writeOff->id,
                'note' => $writeOff->reason,
                'created_by' => $by->id,
            ]);

            RepaymentPlan::allocate($entry, LoanPosition::of($loan), 0, $forgo, $writeOff->effective_date->copy());
            LoanStatusSync::refresh($loan);
            activity()->performedOn($loan)->causedBy($by)->log("Interest forgone on {$loan->loan_number}: ".Money::ofMinor($forgo)->format());
        }

        $writeOff->update(['status' => LoanWriteOff::POSTED, 'posted_at' => now()]);
    }

    private function money(mixed $value, string $field): int
    {
        try {
            $money = Money::ofTaka((string) (($value === null || $value === '') ? '0' : $value));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter the amount in taka.']);
        }

        if ($money->minor < 0) {
            throw ValidationException::withMessages([$field => 'The amount cannot be negative.']);
        }

        return $money->minor;
    }

    private function head(string $slug): Account
    {
        return Account::bySlug($slug) ?? throw ValidationException::withMessages(['loan' => "The chart has no {$slug} head."]);
    }
}
