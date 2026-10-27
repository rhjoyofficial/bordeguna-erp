<?php

namespace App\Modules\Hrm\Reporting;

use App\Modules\Hrm\Support\PayrollCycle;

/**
 * One input a report asks for, declared by its definition.
 *
 * The definition declares; the framework renders the control, validates the
 * value and puts it in the bag. No report writes a form and no report writes a
 * validation rule — which is the difference between fifteen thin definitions
 * and fifteen small applications.
 */
final class ReportParameter
{
    /**
     * @param  array<int, array{value: string|int, label: string}>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ReportParameterType $type,
        public readonly bool $required = true,
        public readonly array $options = [],
        public readonly ?string $help = null,
        public readonly mixed $default = null,
    ) {}

    public static function date(string $key, string $label, bool $required = true, ?string $default = null): self
    {
        return new self($key, $label, ReportParameterType::Date, $required, default: $default);
    }

    public static function dateRange(string $key = 'range', string $label = 'Date range', bool $required = true): self
    {
        return new self($key, $label, ReportParameterType::DateRange, $required);
    }

    /**
     * ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23".
     * The default is the label of the payroll cycle containing today (on 27 Oct
     * that is November, 24 Oct - 23 Nov), the same cycle `monthStart()` falls
     * back to. It was the calendar month, which after the 24th opened every HR
     * report on the cycle that had already closed.
     */
    public static function month(string $key = 'month', string $label = 'Month', bool $required = true, ?string $default = null): self
    {
        if ($default === null) {
            $cycle = PayrollCycle::containing(now());
            $default = sprintf('%04d-%02d', $cycle['year'], $cycle['month']);
        }

        return new self($key, $label, ReportParameterType::Month, $required, default: $default);
    }

    public static function period(string $key = 'period_id', string $label = 'Payroll period', bool $required = true): self
    {
        return new self($key, $label, ReportParameterType::Period, $required);
    }

    public static function payrollRun(string $key = 'run_id', string $label = 'Payroll run', bool $required = true): self
    {
        return new self($key, $label, ReportParameterType::PayrollRun, $required);
    }

    public static function office(string $key = 'office_id', string $label = 'Office', bool $required = false): self
    {
        return new self($key, $label, ReportParameterType::Office, $required);
    }

    public static function department(string $key = 'department_id', string $label = 'Department', bool $required = false): self
    {
        return new self($key, $label, ReportParameterType::Department, $required);
    }

    public static function employee(string $key = 'employee_id', string $label = 'Employee', bool $required = false): self
    {
        return new self($key, $label, ReportParameterType::Employee, $required);
    }

    /**
     * @param  array<int, array{value: string|int, label: string}>  $options
     */
    public static function select(string $key, string $label, array $options, bool $required = false, mixed $default = null): self
    {
        return new self($key, $label, ReportParameterType::Select, $required, $options, default: $default);
    }

    public static function boolean(string $key, string $label, bool $default = false, ?string $help = null): self
    {
        return new self($key, $label, ReportParameterType::Boolean, false, help: $help, default: $default);
    }

    /**
     * The validation rules this parameter contributes. Range types contribute
     * two, which is why this returns a map rather than a list.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $presence = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            ReportParameterType::Date => [$this->key => "{$presence}|date"],
            ReportParameterType::DateRange => [
                "{$this->key}_from" => "{$presence}|date",
                // A range that ends before it starts is not a narrow range, it
                // is a mistake — and it silently returns nothing, which reads
                // as "no data" rather than "bad input".
                "{$this->key}_to" => "{$presence}|date|after_or_equal:{$this->key}_from",
            ],
            // Pinned to the format rather than left to `date`, which would
            // happily accept "2026-07-15" and quietly discard the day.
            ReportParameterType::Month => [$this->key => "{$presence}|date_format:Y-m"],
            ReportParameterType::Boolean => [$this->key => 'nullable|boolean'],
            ReportParameterType::Select => [$this->key => "{$presence}|string"],
            default => [$this->key => "{$presence}|integer"],
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'options' => $this->options,
            'help' => $this->help,
            'default' => $this->default,
        ];
    }
}
