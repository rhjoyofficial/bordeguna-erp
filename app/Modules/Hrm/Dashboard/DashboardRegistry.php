<?php

namespace App\Modules\Hrm\Dashboard;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Reporting\ReportScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Every widget the dashboard knows about, which of them a given user may see,
 * and their cached values.
 *
 * ⚠ **Correction C10d is enforced here, by the same `ReportScope` the reports
 * use, and C11d extends it to tiles unchanged.** A Department Manager gets no
 * payroll or salary tile *at all* — not scoped, not summarised, not greyed out.
 * A tile they may not see is **absent from the payload**: a disabled tile
 * labelled "Total payroll" still tells them a figure exists and roughly where to
 * ask for it.
 *
 * **The list is filtered, never the value refused.** Same reasoning as
 * `ReportRegistry`: a widget a user cannot see should not be rendered for them
 * to wonder about.
 */
class DashboardRegistry
{
    /** @var array<string, DashboardWidget> */
    private array $widgets = [];

    /**
     * @param  iterable<DashboardWidget>  $widgets
     */
    public function __construct(
        private readonly BusinessContext $context,
        iterable $widgets = [],
    ) {
        foreach ($widgets as $widget) {
            $this->register($widget);
        }
    }

    public function register(DashboardWidget $widget): void
    {
        $this->widgets[$widget->key()] = $widget;
    }

    /** @return Collection<string, DashboardWidget> */
    public function all(): Collection
    {
        return collect($this->widgets);
    }

    /** @return Collection<string, DashboardWidget> */
    public function availableTo(User $user): Collection
    {
        return $this->all()->filter(fn (DashboardWidget $w) => $this->canSee($user, $w));
    }

    public function canSee(User $user, DashboardWidget $widget): bool
    {
        // The widget's own answer, which for almost all of them is "do they hold
        // the permission I declare". ⚠ Everything below is applied ON TOP and is
        // not overridable — a widget may narrow its audience, never widen it.
        if (! $widget->isVisibleTo($user)) {
            return false;
        }

        $scope = ReportScope::for($user);

        if (! $scope->restricted) {
            return true;
        }

        // ACL-02 / C5, extended to tiles by C11d. A Manager holding a payroll
        // permission through some other grant still gets no payroll tile — the
        // rule is about the data, not about the permission guarding it.
        if ($widget->group() === 'Payroll') {
            return false;
        }

        // Fail closed, exactly as ReportRegistry does: a manager the system
        // cannot place cannot be scoped, so they see nothing rather than
        // everything.
        return $scope->resolvable();
    }

    /**
     * Resolve every visible widget's value, cached per Correction C11e.
     *
     * ⚠ A widget declaring `cacheSeconds() === 0` is computed live. That is not
     * an oversight to tidy up later — a pending-approvals count is what somebody
     * is about to act on, and a minute-old one sends them to an inbox that has
     * already changed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveFor(User $user, DashboardFilters $filters): array
    {
        return $this->availableTo($user)
            ->map(function (DashboardWidget $widget) use ($filters) {
                $value = $this->cached($widget, $filters);

                return [
                    'key' => $widget->key(),
                    'title' => $widget->title(),
                    'description' => $widget->description(),
                    'group' => $widget->group(),
                    ...$value->toArray(),
                ];
            })
            ->values()
            ->all();
    }

    private function cached(DashboardWidget $widget, DashboardFilters $filters): WidgetValue
    {
        $seconds = $widget->cacheSeconds();

        if ($seconds <= 0) {
            return $widget->value($filters);
        }

        return Cache::remember(
            $filters->cacheKey($widget->key(), $this->context->id()),
            $seconds,
            fn () => $widget->value($filters),
        );
    }
}
