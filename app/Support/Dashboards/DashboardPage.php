<?php

namespace App\Support\Dashboards;

use App\Models\User;
use App\Modules\Foundation\Models\DashboardLayout;
use Illuminate\Support\Collection;

/**
 * Applies one person's layout to the widgets they are authorised for, and
 * resolves the visible ones.
 *
 * ⚠ The authorised set comes first and the layout only reorders or hides
 * inside it: a key in the saved layout that the person may no longer see is
 * simply ignored, and a hidden widget is not computed.
 */
class DashboardPage
{
    public function __construct(private readonly WidgetRegistry $registry) {}

    /**
     * @return array{visible: Collection<int, Widget>, hidden: Collection<int, Widget>}
     */
    public function arrange(DashboardKey $dashboard, User $user, int $businessId): array
    {
        $authorised = $this->registry->forDashboard($dashboard, $user);

        $layout = DashboardLayout::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('dashboard', $dashboard->value)
            ->first();

        $hiddenKeys = $layout !== null
            ? (array) ($layout->hidden ?? [])
            : $authorised->reject(fn (Widget $w) => $w->defaultVisible())->keys()->all();

        $order = array_values(array_intersect((array) ($layout?->widget_order ?? []), $authorised->keys()->all()));
        $sorted = collect($order)
            ->map(fn (string $key) => $authorised->get($key))
            ->merge($authorised->except($order)->values())
            ->values();

        return [
            'visible' => $sorted->reject(fn (Widget $w) => in_array($w->key(), $hiddenKeys, true))->values(),
            'hidden' => $sorted->filter(fn (Widget $w) => in_array($w->key(), $hiddenKeys, true))->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function payload(Collection $widgets, WidgetContext $context): array
    {
        return $widgets
            ->map(fn (Widget $widget) => $widget->meta($context) + ['result' => $this->registry->resolve($widget, $context)])
            ->values()
            ->all();
    }
}
