<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDayCount;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Models\LoanAmendment;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanScheduleRow;
use App\Modules\Accounting\Models\LoanTermsVersion;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanTerms;
use App\Modules\Accounting\Support\Loans\ScheduleBuilder;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A restructure (loans plan v2, phase 2): new terms for the principal still
 * owed, from a date, approved by the Owner.
 *
 * ⚠⚠ HISTORY IS KEPT, NEVER REWRITTEN. Applying an amendment ADDS terms
 * version n+1 and a new schedule for the outstanding principal from the
 * effective date; every earlier version, schedule row and payment against it
 * stays as it was. Unpaid interest from the old schedule is carried to the
 * first new installment as interest (never into principal).
 *
 * ⚠ Accruals already posted are never touched: the next "Accrue interest" for
 * an affected month works out the month again under the new terms and posts a
 * controlled adjustment for the difference (AccrueLoanInterest).
 *
 * ⚠ The effective date cannot fall before money already recorded under the
 * old terms (the new schedule starts from what is owed on that day), nor
 * before the previous version took effect. Backdating INTO an accrued month is
 * fine: that is exactly what the adjustment is for.
 */
class LoanAmendments
{
    public function __construct(
        private readonly LoanApprovals $approvals,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function request(LoanContract $loan, array $data, User $by): LoanAmendment
    {
        if ($loan->status !== LoanStatus::Active) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}. Only an active loan is restructured; a draft is simply edited."]);
        }

        if (LoanAmendment::query()->where('loan_contract_id', $loan->id)->where('status', LoanAmendment::PENDING)->exists()) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} already has an amendment waiting for the Owner."]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the terms are changing (what was agreed, with whom, and when).']);
        }

        $effective = $this->effectiveDate($loan, $data['effective_from'] ?? null);
        $terms = $this->terms($loan, $data, $effective);

        $amendment = new LoanAmendment([
            'business_id' => $loan->business_id,
            'loan_contract_id' => $loan->id,
            'status' => LoanAmendment::PENDING,
            'from_version' => (int) $loan->terms_version,
            'effective_from' => $effective->toDateString(),
            'terms' => $terms->toArray(),
            'reason' => $reason,
            'created_by' => $by->id,
        ]);

        $this->approvals->assertWorkflow($amendment, 'loan amendments');

        $request = DB::transaction(function () use ($amendment, $by, $loan) {
            $amendment->save();
            activity()->performedOn($loan)->causedBy($by)->withProperties(['amendment_id' => $amendment->id])->log("Asked the Owner to restructure {$loan->loan_number}");

            return $amendment->submitForApproval($by);
        });

        $this->notify->handle($request, "Restructure of loan {$loan->loan_number} ({$loan->counterparty?->legal_name}) is waiting for your approval");

        return $amendment;
    }

    /** @return bool whether it was applied (false: moved to a later stage) */
    public function approve(LoanAmendment $amendment, User $by, ?string $note = null): bool
    {
        $approval = $amendment->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return DB::transaction(function () use ($amendment, $approval, $by, $note) {
            if (! $approval->approve($by, $note)) {
                return false;
            }

            $this->apply($amendment->fresh(), $by);

            return true;
        });
    }

    public function reject(LoanAmendment $amendment, User $by, ?string $note): void
    {
        $approval = $amendment->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        DB::transaction(function () use ($amendment, $approval, $by, $note) {
            $approval->reject($by, $note);
            $amendment->update(['status' => LoanAmendment::REJECTED]);
            activity()->performedOn($amendment->loan)->causedBy($by)->withProperties(['note' => $note])->log('Restructure turned down');
        });
    }

    private function apply(LoanAmendment $amendment, User $by): void
    {
        $loan = LoanContract::query()->lockForUpdate()->findOrFail($amendment->loan_contract_id);

        if ($amendment->status !== LoanAmendment::PENDING || (int) $loan->terms_version !== $amendment->from_version) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number}'s terms changed after this amendment was asked for. Ask again on the current terms."]);
        }

        $effective = $amendment->effective_from->copy()->startOfDay();
        // Money recorded since it was asked for would fall outside the new schedule.
        $this->effectiveDate($loan, $effective->toDateString());
        $terms = LoanTerms::fromArray($amendment->terms);
        $position = LoanPosition::of($loan);
        $version = (int) $loan->terms_version + 1;

        // Interest the old schedule says was due by the effective date and is unpaid.
        $arrears = $position->dueInterest($effective);

        $rows = $terms->repaymentType === LoanRepaymentType::Custom
            ? throw ValidationException::withMessages(['repayment_type' => 'A restructure generates its schedule; a custom one is not offered here.'])
            : ScheduleBuilder::build($terms, max(0, $position->principal), $effective, $arrears);

        LoanTermsVersion::query()->create([
            'loan_contract_id' => $loan->id,
            'version' => $version,
            'effective_from' => $effective->toDateString(),
            'terms' => $terms->toArray(),
            'reason' => $amendment->reason,
            'created_by' => $by->id,
        ]);

        foreach ($rows as $index => $row) {
            LoanScheduleRow::query()->create([
                'business_id' => $loan->business_id,
                'loan_contract_id' => $loan->id,
                'version' => $version,
                'sequence' => $index + 1,
                'due_date' => $row['due_date'],
                'principal_due_minor' => $row['principal_minor'],
                'interest_due_minor' => $row['interest_minor'],
            ]);
        }

        $loan->update([
            'terms_version' => $version,
            'annual_rate' => LoanMath::rateString($terms->rateE4),
            'interest_free_reason' => $terms->rateE4 === 0 ? $terms->interestFreeReason : null,
            'day_count' => $terms->dayCount,
            'repayment_type' => $terms->repaymentType,
            'frequency' => $terms->frequency,
            'grace_months' => $terms->graceMonths,
            'maturity_date' => $terms->maturityDate->toDateString(),
            'waterfall' => $terms->waterfall,
        ]);

        $amendment->update(['status' => LoanAmendment::APPLIED, 'to_version' => $version, 'applied_at' => now()]);

        activity()->performedOn($loan)->causedBy($by)
            ->withProperties(['from_version' => $version - 1, 'to_version' => $version, 'effective_from' => $effective->toDateString()])
            ->log("Restructured {$loan->loan_number}: terms version {$version} from {$effective->format('j M Y')}");
    }

    private function effectiveDate(LoanContract $loan, mixed $value): Carbon
    {
        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_from' => 'Enter the day the new terms take effect.']);
        }

        $previous = LoanTermsVersion::query()->where('loan_contract_id', $loan->id)->max('effective_from');

        if ($previous !== null && $date->lessThanOrEqualTo(Carbon::parse($previous))) {
            throw ValidationException::withMessages(['effective_from' => 'The new terms must take effect after the current ones did ('.Carbon::parse($previous)->format('j M Y').').']);
        }

        $lastMoney = LoanEntry::query()->live()
            ->where('loan_contract_id', $loan->id)
            ->whereNotIn('kind', [LoanEntryKind::Accrual->value, LoanEntryKind::AccrualAdjustment->value])
            ->max('entry_date');

        if ($lastMoney !== null && $date->lessThan(Carbon::parse($lastMoney))) {
            throw ValidationException::withMessages(['effective_from' => 'Money was recorded on this loan on '.Carbon::parse($lastMoney)->format('j M Y').'. The new terms can start on that day or later.']);
        }

        return $date;
    }

    /** @param  array<string, mixed>  $data */
    private function terms(LoanContract $loan, array $data, Carbon $effective): LoanTerms
    {
        try {
            $rate = LoanMath::rateE4((string) ($data['annual_rate'] ?? $loan->annual_rate));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['annual_rate' => 'Enter the rate as a percentage a year.']);
        }

        $reason = trim((string) ($data['interest_free_reason'] ?? '')) ?: null;

        if ($rate === 0 && $reason === null) {
            throw ValidationException::withMessages(['interest_free_reason' => 'An interest-free loan needs its reason written down.']);
        }

        try {
            $maturity = Carbon::parse((string) ($data['maturity_date'] ?? $loan->maturity_date->toDateString()))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['maturity_date' => 'Enter the new maturity date.']);
        }

        if (! $maturity->greaterThan($effective)) {
            throw ValidationException::withMessages(['maturity_date' => 'The loan must mature after the new terms take effect.']);
        }

        $type = LoanRepaymentType::tryFrom((string) ($data['repayment_type'] ?? $loan->repayment_type->value));
        $frequency = LoanFrequency::tryFrom((string) ($data['frequency'] ?? $loan->frequency->value));

        if ($type === null || $type === LoanRepaymentType::Custom) {
            throw ValidationException::withMessages(['repayment_type' => 'Choose equal installments or bullet for the new schedule.']);
        }

        if ($frequency === null || ($type === LoanRepaymentType::EqualInstallments && $frequency === LoanFrequency::AtMaturity)) {
            throw ValidationException::withMessages(['frequency' => 'Equal installments fall due monthly or quarterly.']);
        }

        $grace = (int) ($data['grace_months'] ?? 0);

        if ($grace < 0 || ($grace > 0 && ! $effective->copy()->addMonthsNoOverflow($grace)->lessThan($maturity))) {
            throw ValidationException::withMessages(['grace_months' => 'The grace period must end before the loan matures.']);
        }

        return new LoanTerms(
            rateE4: $rate,
            dayCount: LoanDayCount::tryFrom((string) ($data['day_count'] ?? $loan->day_count->value)) ?? LoanDayCount::Actual365,
            repaymentType: $type,
            frequency: $frequency,
            graceMonths: $grace,
            maturityDate: $maturity,
            waterfall: LoanWaterfall::tryFrom((string) ($data['waterfall'] ?? $loan->waterfall->value)) ?? LoanWaterfall::InterestFirst,
            interestFreeReason: $rate === 0 ? $reason : null,
        );
    }
}
