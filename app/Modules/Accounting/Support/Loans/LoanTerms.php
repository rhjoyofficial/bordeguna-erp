<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanDayCount;
use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Models\LoanContract;
use Illuminate\Support\Carbon;

/**
 * The terms a loan runs on from a given day: the rate and how it is counted,
 * how the principal comes back, and in what order a repayment settles things.
 *
 * Version 1 is the approved agreement; an amendment writes the next version
 * (LoanTermsVersion). Principal and start date are not terms here: a version
 * applies to whatever principal is owed on the day it takes effect.
 */
final class LoanTerms
{
    public function __construct(
        public readonly int $rateE4,
        public readonly LoanDayCount $dayCount,
        public readonly LoanRepaymentType $repaymentType,
        public readonly LoanFrequency $frequency,
        public readonly int $graceMonths,
        public readonly Carbon $maturityDate,
        public readonly LoanWaterfall $waterfall,
        public readonly ?string $interestFreeReason = null,
    ) {}

    public static function fromContract(LoanContract $loan): self
    {
        return new self(
            rateE4: $loan->rateE4(),
            dayCount: $loan->day_count,
            repaymentType: $loan->repayment_type,
            frequency: $loan->frequency,
            graceMonths: (int) $loan->grace_months,
            maturityDate: $loan->maturity_date->copy()->startOfDay(),
            waterfall: $loan->waterfall,
            interestFreeReason: $loan->interest_free_reason,
        );
    }

    /** @param  array<string, mixed>  $terms  as stored on a terms version or an amendment */
    public static function fromArray(array $terms): self
    {
        return new self(
            rateE4: LoanMath::rateE4((string) ($terms['annual_rate'] ?? '0')),
            dayCount: LoanDayCount::from((string) ($terms['day_count'] ?? LoanDayCount::Actual365->value)),
            repaymentType: LoanRepaymentType::from((string) $terms['repayment_type']),
            frequency: LoanFrequency::from((string) $terms['frequency']),
            graceMonths: (int) ($terms['grace_months'] ?? 0),
            maturityDate: Carbon::parse((string) $terms['maturity_date'])->startOfDay(),
            waterfall: LoanWaterfall::from((string) ($terms['waterfall'] ?? LoanWaterfall::InterestFirst->value)),
            interestFreeReason: $terms['interest_free_reason'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'annual_rate' => LoanMath::rateString($this->rateE4),
            'day_count' => $this->dayCount->value,
            'repayment_type' => $this->repaymentType->value,
            'frequency' => $this->frequency->value,
            'grace_months' => $this->graceMonths,
            'maturity_date' => $this->maturityDate->toDateString(),
            'waterfall' => $this->waterfall->value,
            'interest_free_reason' => $this->interestFreeReason,
        ];
    }

    public function basis(): int
    {
        return $this->dayCount->basis();
    }
}
