<?php

namespace App\Modules\Hrm\Reporting;

use Illuminate\Support\Carbon;

/**
 * The validated inputs a report was run with.
 *
 * Read-only, and it carries its own labels. That second part matters more than
 * it looks: a report handed to Finance three weeks later must say on its face
 * which office and which period produced it, and the only moment we know the
 * *label* behind `office_id = 4` is while the parameter screen's options are
 * still in hand. Passing ids alone downstream is how a report ends up stating a
 * number nobody can interpret.
 */
final class ReportParameters
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels  human-readable rendering, keyed the same
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $labels = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '' && $this->values[$key] !== null;
    }

    public function int(string $key): ?int
    {
        return $this->has($key) ? (int) $this->values[$key] : null;
    }

    public function string(string $key): ?string
    {
        return $this->has($key) ? (string) $this->values[$key] : null;
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    public function date(string $key): ?Carbon
    {
        return $this->has($key) ? Carbon::parse((string) $this->values[$key]) : null;
    }

    /**
     * A `YYYY-MM` parameter as the first day of that month.
     *
     * Returns the *start*, so a caller that wants the whole month writes
     * `month()` and `month()->endOfMonth()` rather than parsing anything. A
     * report that reached for `Carbon::parse('2026-07')` itself would get the
     * first of the month on some PHP builds and today's day-of-month on others,
     * which is the kind of bug that only appears after the 28th.
     */
    public function month(string $key = 'month'): ?Carbon
    {
        return $this->has($key)
            ? Carbon::createFromFormat('Y-m-d', $this->values[$key].'-01')->startOfDay()
            : null;
    }

    public function from(string $key = 'range'): ?Carbon
    {
        return $this->date("{$key}_from");
    }

    public function to(string $key = 'range'): ?Carbon
    {
        return $this->date("{$key}_to");
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * The filters as a human reads them, for the report's own header. Only
     * parameters that were actually supplied appear — printing "Office: all"
     * beside six other blank rows buries the two filters that were real.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        return array_filter($this->labels, fn ($v) => $v !== null && $v !== '');
    }

    public function withLabel(string $key, string $label): self
    {
        return new self($this->values, [...$this->labels, $key => $label]);
    }
}
