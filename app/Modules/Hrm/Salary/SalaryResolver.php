<?php

namespace App\Modules\Hrm\Salary;

use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Models\SalaryStructureLine;
use Illuminate\Support\Collection;

/**
 * Turns a structure's stored figures into money (SAL-01/C3, SAL-04, PAY-10).
 *
 * Pure: it takes lines and returns a breakdown, touching nothing else. The
 * resolution runs in a fixed order that keeps percentages non-circular —
 * Basic, then the remaining earnings (which may be a share of Basic), then
 * Gross, then deductions (which may be a share of either). An earning can
 * never be a percentage of Gross, because Gross is the sum of the earnings.
 *
 * Every line is rounded to 2 decimals as it is resolved, per PAY-10's
 * per-component rounding rule, rather than rounding only the total.
 */
class SalaryResolver
{
    /**
     * @param  Collection<int, SalaryStructureLine>  $lines  with `component` loaded
     */
    public function resolve(Collection $lines): SalaryBreakdown
    {
        $lines = $lines->filter(fn (SalaryStructureLine $line) => $line->component !== null);

        if ($lines->isEmpty()) {
            return SalaryBreakdown::empty();
        }

        $basicLine = $lines->first(fn (SalaryStructureLine $line) => $line->component->is_basic);
        $basic = $basicLine !== null ? round((float) $basicLine->value, 2) : 0.0;

        $resolved = [];

        // 1. Basic — always a fixed amount, and the base for everything else.
        if ($basicLine !== null) {
            $resolved[] = $this->line($basicLine, $basic, number_format($basic, 2));
        }

        // 2. The remaining earnings, which may be a share of Basic.
        $gross = $basic;

        foreach ($this->sorted($lines, SalaryComponentType::Earning) as $line) {
            if ($line->component->is_basic) {
                continue;
            }

            [$amount, $formula] = $this->amountFor($line, $basic, gross: 0.0);
            $resolved[] = $this->line($line, $amount, $formula);
            $gross = round($gross + $amount, 2);
        }

        // 3. Deductions, now that Gross is known.
        $deductionTotal = 0.0;

        foreach ($this->sorted($lines, SalaryComponentType::Deduction) as $line) {
            [$amount, $formula] = $this->amountFor($line, $basic, $gross);
            $resolved[] = $this->line($line, $amount, $formula);
            $deductionTotal = round($deductionTotal + $amount, 2);
        }

        return new SalaryBreakdown($resolved, $basic, $gross, $deductionTotal);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function amountFor(SalaryStructureLine $line, float $basic, float $gross): array
    {
        $value = (float) $line->value;

        return match ($line->component->calculation_basis) {
            SalaryCalculationBasis::Fixed => [
                round($value, 2),
                number_format($value, 2),
            ],
            SalaryCalculationBasis::PercentOfBasic => [
                round($basic * $value / 100, 2),
                sprintf('%s%% of Basic %s', self::trim($value), number_format($basic, 2)),
            ],
            SalaryCalculationBasis::PercentOfGross => [
                round($gross * $value / 100, 2),
                sprintf('%s%% of Gross %s', self::trim($value), number_format($gross, 2)),
            ],
        };
    }

    /**
     * @param  Collection<int, SalaryStructureLine>  $lines
     * @return Collection<int, SalaryStructureLine>
     */
    private function sorted(Collection $lines, SalaryComponentType $type): Collection
    {
        return $lines
            ->filter(fn (SalaryStructureLine $line) => $line->component->type === $type)
            ->sortBy([
                fn (SalaryStructureLine $line) => $line->component->sequence,
                fn (SalaryStructureLine $line) => $line->component->name,
            ])
            ->values();
    }

    private function line(SalaryStructureLine $line, float $amount, string $formula): ResolvedSalaryLine
    {
        $component = $line->component;

        return new ResolvedSalaryLine(
            componentId: $component->id,
            code: $component->code,
            name: $component->name,
            type: $component->type,
            basis: $component->calculation_basis,
            isBasic: $component->is_basic,
            isStatutory: $component->is_statutory,
            value: (float) $line->value,
            amount: $amount,
            formula: $formula,
        );
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
