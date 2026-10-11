<?php

namespace App\Support\Dashboards;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every role-dashboard widget, which of them a user may see on a dashboard, and
 * their payloads, cached per business, per user scope, per range.
 *
 * ⚠⚠ A widget the user may not see is never computed and never sent. Neither
 * is one they hid: hiding is presentation (dashboard_layouts) and changes no
 * access, but a hidden widget has no reason to cost a query either.
 *
 * ⚠ A provider that throws gives an ERROR state, never a zero (ruling 9): a
 * dashboard that silently shows 0 when the query failed is believed.
 */
class WidgetRegistry
{
    /** @var array<string, Widget> */
    private array $widgets = [];

    /** @param iterable<Widget> $widgets */
    public function __construct(iterable $widgets = [])
    {
        foreach ($widgets as $widget) {
            $this->register($widget);
        }
    }

    public function register(Widget $widget): void
    {
        $this->widgets[$widget->key()] = $widget;
    }

    /** @return Collection<string, Widget> */
    public function all(): Collection
    {
        return collect($this->widgets);
    }

    public function find(string $key): ?Widget
    {
        return $this->widgets[$key] ?? null;
    }

    /**
     * The widgets of one dashboard this user is authorised to see, in
     * registration order.
     *
     * @return Collection<string, Widget>
     */
    public function forDashboard(DashboardKey $dashboard, User $user): Collection
    {
        return $this->all()->filter(
            fn (Widget $widget) => in_array($dashboard, $widget->dashboards(), true) && $widget->authorize($user)
        );
    }

    public function cacheKey(Widget $widget, WidgetContext $context): string
    {
        $range = $widget->dateBasis() === null ? 'now' : $context->range->cacheFragment();

        $parts = [
            'dash', 'w', $widget->key(),
            'b'.$context->businessId,
            $widget->scope()->cacheFragment($context->user),
            $range,
        ];

        // ⚠ A brand-aware figure is a different figure per brand: never hand
        // LaLaDia's count to somebody looking at Bionic, or at all brands.
        if ($widget->brandAware() && $context->brandId !== null) {
            $parts[] = 'br'.$context->brandId;
        }

        return implode(':', $parts);
    }

    /**
     * @return array{key: string, status: string, data: ?array, message: ?string, generated_at: string}
     */
    public function resolve(Widget $widget, WidgetContext $context): array
    {
        try {
            $compute = fn () => ['data' => $widget->data($context), 'generated_at' => now()->toIso8601String()];

            $result = $widget->cacheSeconds() > 0
                ? Cache::remember($this->cacheKey($widget, $context), $widget->cacheSeconds(), $compute)
                : $compute();

            return [
                'key' => $widget->key(),
                'status' => 'ok',
                'data' => $result['data'],
                'message' => null,
                'generated_at' => $result['generated_at'],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'key' => $widget->key(),
                'status' => 'error',
                'data' => null,
                'message' => 'This figure could not be loaded. It will try again on its own.',
                'generated_at' => now()->toIso8601String(),
            ];
        }
    }
}
