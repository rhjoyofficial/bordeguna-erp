<?php

namespace App\Modules\Hrm\Payroll\Data;

use App\Modules\Hrm\Enums\SalaryComponentType;

/**
 * One line of a payslip, carrying its own derivation.
 *
 * PAY-13's recommendation is explicit that every amount stores its source,
 * formula and quantity/rate. A line holding only a number cannot answer "why is
 * this figure what it is" once the rules have moved on — and payroll is exactly
 * the place that question gets asked, years later, by someone whose pay it was.
 *
 * `amount` is always positive; `type` decides which side of the payslip it
 * lands on.
 */
final readonly class PayslipLineData
{
    public function __construct(
        public string $code,
        public string $label,
        public SalaryComponentType $type,
        public float $amount,
        public ?float $quantity,
        public ?float $rate,
        public string $formula,
        public string $source,
    ) {}

    public function isEarning(): bool
    {
        return $this->type === SalaryComponentType::Earning;
    }

    public function isDeduction(): bool
    {
        return $this->type === SalaryComponentType::Deduction;
    }

    /**
     * The line's contribution to net — the only place the sign is applied.
     */
    public function signedAmount(): float
    {
        return round($this->amount * $this->type->sign(), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'formula' => $this->formula,
            'source' => $this->source,
        ];
    }
}
