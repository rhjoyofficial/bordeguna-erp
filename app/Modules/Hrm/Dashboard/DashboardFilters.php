<?php

namespace App\Modules\Hrm\Dashboard;

use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportScope;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Support\Carbon;

/**
 * What the dashboard was asked for: office, department and a date range.
 *
 * **It wraps `ReportParameters` rather than replacing it**, so the filter
 * vocabulary is shared with W3 and a widget can hand its filters straight to a
 * report definition without translating anything. That sharing is what makes
 * C11a's report-backed widgets possible at all — the tile and the report are
 * asked the same question, in the same words.
 *
 * ⚠ **The scope is applied at construction and cannot be edited afterwards.**
 * `ReportScope::apply()` overwrites `department_id` for a restricted manager, so
 * by the time a widget sees these filters the C10d decision has already been
 * taken. A widget therefore cannot accidentally widen its own view, and does not
 * need to remember to narrow it.
 */
final class DashboardFilters
{
    /**
     * Per-request memo for values several widgets would otherwise each compute.
     *
     * ⚠⚠ **It lives here, on an object built once per request, and NOT in a
     * method-level `static`.** That was the first implementation and it was
     * wrong in a way tests exposed immediately and production would have
     * exposed slowly: a `static` inside a method persists for the life of the
     * *process*, so under Octane or a queue worker one request's freshness —
     * including a scoped manager's unprocessed count — would be served to the
     * next, and it would never expire. Tying the memo to the filters ties it to
     * exactly the lifetime it should have.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    private function __construct(
        public readonly ReportParameters $parameters,
        public readonly ReportScope $scope,
    ) {}

    /**
     * @template T
     *
     * @param  \Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, \Closure $resolve): mixed
    {
        return $this->memo[$key] ??= $resolve();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function make(array $values, ReportScope $scope): self
    {
        return new self(new ReportParameters($scope->apply($values)), $scope);
    }

    public function officeId(): ?int
    {
        return $this->parameters->int('office_id');
    }

    public function departmentId(): ?int
    {
        return $this->parameters->int('department_id');
    }

    /**
     * The range, defaulting to the current PAYROLL month.
     *
     * A dashboard opened with no filters must show something meaningful rather
     * than nothing, and "this month" is the answer everybody means - but since
     * 23 Aug 2026 "this month" is the payroll cycle (24th to 23rd for Bor De
     * Guna, per business setting), because that is the month attendance is
     * judged and paid in. A calendar-month default would show a period no
     * payslip will ever cover.
     */
    public function from(): Carbon
    {
        return $this->parameters->from('range')
            ?? $this->remember('cycle', fn () => PayrollCycle::containing(now()))['start']->copy();
    }

    public function to(): Carbon
    {
        return $this->parameters->to('range')
            ?? $this->remember('cycle', fn () => PayrollCycle::containing(now()))['end']->copy();
    }

    /**
     * The single date the "today" tiles are about.
     *
     * ⚠ It is the *end* of the range, not `now()` — a dashboard filtered to last
     * month should show that month's last day, not today's. **Clamped to today
     * when the range ends in the future**, because there is no attendance for
     * tomorrow and a tile claiming to describe it reads as data loss. The
     * default range is the current month, whose end is always in the future, so
     * this clamp is on the ordinary path rather than an edge case.
     */
    public function asAt(): Carbon
    {
        $end = $this->to();

        return $end->isAfter(now()) ? now()->startOfDay() : $end->copy()->startOfDay();
    }

    /**
     * The month the month-shaped tiles are about — the range end's month, **not
     * clamped**.
     *
     * ⚠⚠ **Caught in the live check, and the distinction from `asAt()` is the
     * whole point.** A dashboard filtered to August while today is 28 July used
     * `asAt()`, which clamped into July and quietly answered about a month
     * nobody had asked for — the tile said "this month" and showed the previous
     * one. Clamping is right for a single date (there is no attendance
     * tomorrow) and wrong for a month (an empty August is a true and useful
     * answer, and a July figure under an August filter is not).
     */
    public function month(): Carbon
    {
        return $this->to()->copy()->startOfMonth();
    }

    /**
     * ⚠⚠ **The cache key includes the resolved scope, not merely the filters**
     * (Correction C11e). Two users looking at the same page with different
     * department scopes must never share an entry — that would be C10d leaking
     * through the cache, which is the one place an access rule can be undone
     * without anybody editing the access rule.
     */
    public function cacheKey(string $widgetKey, ?int $businessId): string
    {
        return implode(':', [
            'hrm.dashboard',
            $widgetKey,
            $businessId ?? 'none',
            $this->scope->restricted ? 'dept-'.($this->scope->departmentId ?? 'none') : 'all',
            md5(serialize($this->parameters->all())),
        ]);
    }
}
