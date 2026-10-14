<?php

namespace App\Support\Dashboards;

use App\Models\User;
use App\Modules\Foundation\Models\DashboardLayout;
use Illuminate\Support\Collection;

/**
 * Applies one person's layout to the widgets they are authorised for, and
 * resolves the visible ones.
 *
 * ⚠ The authorised set comes first and the layout only hides inside it: a key
 * in the saved layout that the person may no longer see is simply ignored, and
 * a hidden widget is not computed.
 *
 * ⚠ 14 Oct 2026: a stored `widget_order` is IGNORED. The page always shows the
 * dashboard's designed order (registration order) and designed sizes; only the
 * hidden list survives, so a layout broken by the old reorder controls resets
 * itself without touching data.
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

        $sorted = $authorised->values();

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
