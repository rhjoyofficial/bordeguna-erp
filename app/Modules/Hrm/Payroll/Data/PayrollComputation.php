<?php

namespace App\Modules\Hrm\Payroll\Data;

/**
 * The value that travels through the payroll pipeline, accumulating lines as
 * each stage adds them. Immutable: a stage returns a new instance, so the
 * ordered sequence of stages is the only thing that can change a payslip.
 *
 * `notes` and `blockReasons` are not decoration. A payslip that cannot explain
 * itself is indefensible to the person it pays, and PAY-09/PAY-11 both require
 * a blocked line to say plainly why.
 */
final readonly class PayrollComputation
{
    /**
     * @param  array<int, PayslipLineData>  $lines
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $blockReasons
     */
    public function __construct(
        public EmployeePayrollContext $context,
        public array $lines = [],
        public array $notes = [],
        public array $blockReasons = [],
    ) {}

    public static function start(EmployeePayrollContext $context): self
    {
        return new self($context);
    }

    public function withLine(PayslipLineData $line): self
    {
        return new self($this->context, [...$this->lines, $line], $this->notes, $this->blockReasons);
    }

    public function note(string $note): self
    {
        return new self($this->context, $this->lines, [...$this->notes, $note], $this->blockReasons);
    }

    public function block(string $reason): self
    {
        return new self($this->context, $this->lines, $this->notes, [...$this->blockReasons, $reason]);
    }

    public function when(bool $condition, callable $callback): self
    {
        return $condition ? $callback($this) : $this;
    }

    public function totalEarnings(): float
    {
        return round(array_sum(array_map(
            fn (PayslipLineData $line) => $line->isEarning() ? $line->amount : 0,
            $this->lines,
        )), 2);
    }

    public function totalDeductions(): float
    {
        return round(array_sum(array_map(
            fn (PayslipLineData $line) => $line->isDeduction() ? $line->amount : 0,
            $this->lines,
        )), 2);
    }
}
